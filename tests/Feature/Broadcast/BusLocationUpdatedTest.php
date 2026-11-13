<?php

use App\Events\BusLocationUpdated;
use App\Models\GpsLocation;
use App\Models\Trip;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\post;
use function Pest\Laravel\withHeaders;
use function Pest\Laravel\withToken;

/*
| Real-time GPS location transmission (WebSockets / Laravel Reverb).
|
| When the driver's app sends a new reading, GPS broadcasts BusLocationUpdated on
| the PRIVATE channel `trip.{tripId}` (`private-trip.{tripId}` for Pusher/Echo).
| Clients subscribe through POST /api/gps/broadcasting/auth (via the Gateway).
|
| Who can follow a trip: ANY authenticated user (a passenger at the stop has no
| "ticket" for that trip), while the trip is scheduled or in progress. Rejected:
| no token, invalid token, trip completed / cancelled / nonexistent.
|
| phpunit.xml disables broadcasting (null driver), so these tests switch to a
| Reverb driver with dummy credentials and re-register the channels on it.
| Fan-out to every subscriber of a channel and client reconnection are done by
| Reverb / Laravel Echo themselves (reconnection is covered in the Flutter app).
*/

const BROADCAST_AUTH_URL = '/api/broadcasting/auth';
const PASSENGER_ID = TEST_USER_ID;
const OTHER_PASSENGER_ID = '01a0c691-8000-731c-a034-03cc8fe8136f';

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    app(BroadcastManager::class)->purge();
    require base_path('routes/channels.php');

    Cache::flush();
    Http::fake([
        // The API Gateway knows two passengers and rejects any other token.
        GATEWAY_USER_URL.'*' => function (ClientRequest $request) {
            return match (true) {
                $request->hasHeader('Authorization', 'Bearer passenger-token') => Http::response(authServiceUser(PASSENGER_ID, 'driver')),
                $request->hasHeader('Authorization', 'Bearer other-passenger-token') => Http::response(authServiceUser(OTHER_PASSENGER_ID)),
                default => Http::response(['errors' => [['status' => '401']]], 401),
            };
        },
        '*/arrivals' => Http::response([
            'data' => [
                'type' => 'trip-arrival-estimates',
                'id' => 'trip-id',
                'attributes' => [
                    'arrivals' => [],
                ],
            ],
        ], 200),
    ]);
});

/**
 * Laravel Echo sends the subscription as application/x-www-form-urlencoded.
 *
 * @return array<string, string>
 */
function channelSubscription(string $tripId): array
{
    return ['socket_id' => '1234.5678', 'channel_name' => "private-trip.{$tripId}"];
}

/**
 * A trip in [$status], built with the factory state that satisfies the
 * database execution rules (each status carries its own timestamps).
 */
function tripWithStatus(string $status): Trip
{
    $factory = Trip::factory();

    return match ($status) {
        Trip::STATUS_SCHEDULED => $factory->scheduled()->create(),
        Trip::STATUS_COMPLETED => $factory->completed()->create(),
        Trip::STATUS_CANCELLED => $factory->cancelled()->create(),
        default => $factory->create(),
    };
}

/**
 * The driver (PASSENGER_ID's user is the assigned driver here) sends a reading.
 *
 * @param  array<string, mixed>  $attributes
 */
function sendReading(Trip $trip, array $attributes = []): TestResponse
{
    return withToken('passenger-token')->postJson('/api/locations', gpsLocationPayload($trip->id, $attributes));
}

describe('channel authorization (private-trip.{id})', function () {
    it('authorizes an authenticated client to subscribe to an active trip', function (string $status) {
        $trip = tripWithStatus($status);

        withToken('other-passenger-token')
            ->post(BROADCAST_AUTH_URL, channelSubscription($trip->id), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['auth'])
            ->assertJsonPath('auth', fn (string $auth) => str_starts_with($auth, 'test-key:'));
    })->with([Trip::STATUS_SCHEDULED, Trip::STATUS_IN_PROGRESS]);

    it('authorizes several clients on the same trip channel', function () {
        $trip = Trip::factory()->create();

        foreach (['passenger-token', 'other-passenger-token'] as $token) {
            withToken($token)
                ->post(BROADCAST_AUTH_URL, channelSubscription($trip->id), ['Accept' => 'application/json'])
                ->assertOk()
                ->assertJsonStructure(['auth']);
        }
    });

    it('rejects a trip that is no longer active', function (string $status) {
        $trip = tripWithStatus($status);

        withToken('other-passenger-token')
            ->post(BROADCAST_AUTH_URL, channelSubscription($trip->id), ['Accept' => 'application/json'])
            ->assertForbidden();
    })->with([Trip::STATUS_COMPLETED, Trip::STATUS_CANCELLED]);

    it('rejects a trip deleted by the Dashboard', function () {
        $trip = Trip::factory()->create();
        $trip->delete(); // soft delete (trips.deleted_at)

        withToken('other-passenger-token')
            ->post(BROADCAST_AUTH_URL, channelSubscription($trip->id), ['Accept' => 'application/json'])
            ->assertForbidden();
    });

    it('rejects a trip that does not exist or whose id is not a UUID', function (string $tripId) {
        withToken('other-passenger-token')
            ->post(BROADCAST_AUTH_URL, channelSubscription($tripId), ['Accept' => 'application/json'])
            ->assertForbidden();
    })->with([
        'unknown trip' => fn () => (string) Str::uuid7(),
        'not a UUID' => '42',
    ]);

    it('rejects an unauthenticated subscription without exposing details', function () {
        $trip = Trip::factory()->create();

        $response = post(BROADCAST_AUTH_URL, channelSubscription($trip->id), ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJsonMissingPath('auth');

        expect($response->getContent())->not->toContain('Exception')->not->toContain('vendor/');
        Http::assertNothingSent();
    });

    it('rejects a token rejected by the API Gateway', function () {
        $trip = Trip::factory()->create();

        withToken('expired-token')
            ->post(BROADCAST_AUTH_URL, channelSubscription($trip->id), ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJsonMissingPath('auth');
    });

    it('rejects a request that only carries spoofed identity headers', function () {
        $trip = Trip::factory()->create();

        withHeaders(['X-User-Id' => PASSENGER_ID, 'X-User-Roles' => 'super-admin', 'Accept' => 'application/json'])
            ->post(BROADCAST_AUTH_URL, channelSubscription($trip->id))
            ->assertForbidden();
    });
});

describe('BusLocationUpdated', function () {
    beforeEach(function () {
        Event::fake([BusLocationUpdated::class]);

        $this->trip = Trip::factory()->drivenBy(PASSENGER_ID)->create();
    });

    it('is broadcast on the private channel of the trip when a new reading arrives', function () {
        sendReading($this->trip)->assertCreated();

        Event::assertDispatchedTimes(BusLocationUpdated::class, 1);
        Event::assertDispatched(BusLocationUpdated::class, fn (BusLocationUpdated $event) => collect($event->broadcastOn())->map->name->all() === ["private-trip.{$this->trip->id}"]
            && $event->broadcastAs() === 'BusLocationUpdated');
    });

    it('carries the trip_id and the bus_id of the trip (gps_location -> trip -> bus)', function () {
        sendReading($this->trip)->assertCreated();

        Event::assertDispatched(BusLocationUpdated::class, fn (BusLocationUpdated $event) => $event->broadcastWith()['trip_id'] === $this->trip->id
            && $event->broadcastWith()['bus_id'] === $this->trip->bus_id);
    });

    it('carries the coordinates of the stored GPS location', function () {
        $id = sendReading($this->trip, ['latitude' => 10.4631, 'longitude' => -83.9921])
            ->assertCreated()
            ->json('data.id');

        $stored = GpsLocation::query()->findOrFail($id);

        Event::assertDispatched(BusLocationUpdated::class, fn (BusLocationUpdated $event) => $event->broadcastWith()['id'] === $stored->id
            && $event->broadcastWith()['latitude'] === $stored->latitude
            && $event->broadcastWith()['longitude'] === $stored->longitude);
    });

    it('includes speed_kmh and recorded_at (ISO-8601 UTC) in the payload', function () {
        sendReading($this->trip, ['speed_kmh' => 38.5, 'recorded_at' => '2026-09-03T15:00:00Z'])->assertCreated();

        Event::assertDispatched(BusLocationUpdated::class, function (BusLocationUpdated $event) {
            $payload = $event->broadcastWith();

            return array_keys($payload) === ['id', 'trip_id', 'bus_id', 'latitude', 'longitude', 'speed_kmh', 'accuracy_meters', 'recorded_at']
                && $payload['speed_kmh'] === 38.5
                && $payload['recorded_at'] === '2026-09-03T15:00:00.000000Z';
        });
    });

    it('is broadcast once per reading: every subscriber of the channel gets the same update', function () {
        sendReading($this->trip, ['recorded_at' => '2026-09-03T15:00:00Z'])->assertCreated();

        // One event on ONE channel: Reverb fans it out to all the channel's subscribers,
        // so every client gets the same payload without an extra HTTP request.
        Event::assertDispatchedTimes(BusLocationUpdated::class, 1);
    });
});

describe('broadcast infrastructure failure', function () {
    beforeEach(function () {
        // A broadcaster that always fails, like Reverb being down.
        Broadcast::extend('failing', fn () => new class extends Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new BroadcastException('Pusher error: cURL error 7: Failed to connect to 127.0.0.1 port 8080');
            }
        });
        config([
            'broadcasting.default' => 'failing',
            'broadcasting.connections.failing' => ['driver' => 'failing'],
        ]);
        app(BroadcastManager::class)->purge();

        $this->trip = Trip::factory()->drivenBy(PASSENGER_ID)->create();
    });

    it('keeps the GPS location stored and answers normally', function () {
        Exceptions::fake();

        $response = sendReading($this->trip, ['latitude' => 10.4631, 'longitude' => -83.9921])
            ->assertCreated()
            ->assertJsonPath('data.attributes.latitude', 10.4631);

        $stored = GpsLocation::query()->sole();
        expect($stored->latitude)->toBe(10.4631)
            ->and($stored->longitude)->toBe(-83.9921);

        // No internal technical details leak to the client...
        expect($response->getContent())->not->toContain('cURL')->not->toContain('Pusher');

        // ...but the failure is reported (logs) for diagnosis.
        Exceptions::assertReported(BroadcastException::class);
    });
});
