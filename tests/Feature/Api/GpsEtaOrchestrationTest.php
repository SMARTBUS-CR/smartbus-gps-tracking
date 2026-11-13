<?php

use App\Data\TripPosition;
use App\Events\BusLocationUpdated;
use App\Events\TripArrivalsUpdated;
use App\Models\Driver;
use App\Models\Trip;
use App\Services\EtaOrchestrationService;
use App\Services\TripPositionStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;
use function Pest\Laravel\postJson;

/*
| GPS-02 driver validation, and the arrival estimates (ETA) worked out by
| `gps:eta-watch` apart from the driver's request.
*/

const ETA_TOKEN = 'driver-test-bearer-token';
const ETA_DASHBOARD_URL = 'https://dashboard.test';
const ETA_SERVICE_URL = 'https://eta.test/api/v1/eta';
const ETA_REVISION = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
const ETA_STOP_1 = '01a0c691-1111-731c-a034-03cc8fe8136f';
const ETA_STOP_2 = '01a0c691-3333-731c-a034-03cc8fe8136f';
const ETA_STOP_3 = '01a0c691-5555-731c-a034-03cc8fe8136f';

/**
 * Dashboard ETA context (`projection=eta-v1`): a straight line going north,
 * 1111.949 m long, with stops at its start, middle and end.
 *
 * @return array<string, mixed>
 */
function etaContext(string $tripId): array
{
    $stop = fn (string $id, int $sequence, float $lat, float $distance): array => [
        'occurrence_id' => $id,
        'stop_id' => str_replace('-1', '-9', $id),
        'stop_sequence' => $sequence,
        'name' => "Parada {$sequence}",
        'latitude' => $lat,
        'longitude' => -84.0,
        'planned_arrival_at' => null,
        'distance_along_route_m' => $distance,
    ];

    return [
        'contract_version' => 1,
        'trip_id' => $tripId,
        'route_geometry' => ['type' => 'LineString', 'coordinates' => [[-84.0, 10.0], [-84.0, 10.01]]],
        'route_distance_m' => 1111.949,
        'stop_occurrences' => [
            $stop(ETA_STOP_1, 1, 10.0, 0.0),
            $stop(ETA_STOP_2, 2, 10.005, 555.975),
            $stop(ETA_STOP_3, 3, 10.01, 1111.949),
        ],
        'route_revision' => ETA_REVISION,
        'status' => 'in_progress',
    ];
}

/** ETA service answer for the stops still ahead. */
function etaAnswer(string $tripId): array
{
    return [
        'data' => [
            'type' => 'trip-arrival-estimates',
            'id' => $tripId,
            'attributes' => [
                'contract_version' => 1,
                'arrivals' => [
                    [
                        'stop_occurrence_id' => ETA_STOP_2,
                        'stop_id' => '01a0c691-3333-731c-a034-03cc8fe8136f',
                        'stop_sequence' => 2,
                        'remaining_distance_m' => 222.4,
                        'eta_seconds' => 23,
                        'arrival_at' => '2026-11-13T18:00:23Z',
                        'planned_arrival_at' => '2026-11-13T18:01:00Z',
                        'method' => 'distance_speed',
                        'quality_reason' => 'current_speed',
                        'model_version' => null,
                    ],
                    [
                        'stop_occurrence_id' => ETA_STOP_3,
                        'stop_id' => '01a0c691-5555-731c-a034-03cc8fe8136f',
                        'stop_sequence' => 3,
                        'remaining_distance_m' => 778.4,
                        'eta_seconds' => 78,
                        'arrival_at' => '2026-11-13T18:01:18Z',
                        'planned_arrival_at' => null,
                        'method' => 'distance_speed',
                        'quality_reason' => 'current_speed',
                        'model_version' => null,
                    ],
                ],
            ],
        ],
    ];
}

/** Current position of the bus (what an accepted GPS reading leaves). */
function etaBusAt(string $tripId, float $latitude, ?float $speedKmh = 36.0, ?CarbonImmutable $at = null): void
{
    app(TripPositionStore::class)->put($tripId, new TripPosition(
        $latitude,
        -84.0,
        $speedKmh,
        8.0,
        $at ?? CarbonImmutable::now()->subSecond(),
    ));
}

/** Dashboard and ETA service fakes; any other outgoing request fails the test. */
function fakeEtaServices(string $tripId, mixed $dashboard = null, mixed $eta = null): void
{
    Http::preventStrayRequests();
    Http::fake([
        ETA_DASHBOARD_URL.'/*' => $dashboard ?? Http::response(etaContext($tripId)),
        ETA_SERVICE_URL.'/*' => $eta ?? Http::response(etaAnswer($tripId)),
    ]);
}

function etaRequests(string $host): array
{
    return Http::recorded(fn (ClientRequest $request) => str_starts_with($request->url(), $host))->values()->all();
}

beforeEach(function () {
    Event::fake([BusLocationUpdated::class, TripArrivalsUpdated::class]);
});

describe('GPS-02 driver assignment validation', function () {
    beforeEach(function () {
        actingAsGatewayUser(ETA_TOKEN, role: 'driver');
        $this->trip = Trip::factory()->drivenBy(TEST_USER_ID)->create();
    });

    it('answers 403 when the user is not the driver assigned to the trip', function () {
        $otherTrip = Trip::factory()->create();

        postJson('/api/locations', gpsLocationPayload($otherTrip->id))
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonPath('errors.0.status', '403')
            ->assertJsonPath('errors.0.detail', 'The authenticated user is not the assigned driver for this trip');

        Event::assertNotDispatched(BusLocationUpdated::class);
    });

    it('answers 403 when the assigned driver is inactive', function () {
        $this->trip->driver->forceFill(['status' => 'inactive'])->save();

        postJson('/api/locations', gpsLocationPayload($this->trip->id))
            ->assertForbidden()
            ->assertJsonPath('errors.0.detail', 'The authenticated user is not the assigned driver for this trip');
    });

    it('answers 409 when the trip is not in progress', function (string $status) {
        $trip = Trip::factory()->drivenBy(TEST_USER_ID)->{$status}()->create();

        postJson('/api/locations', gpsLocationPayload($trip->id))
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonPath('errors.0.status', '409')
            ->assertJsonPath('errors.0.detail', 'Trip is not currently in progress');
    })->with(['scheduled', 'completed', 'cancelled']);

    it('answers 404 when the trip does not exist', function () {
        $this->trip->delete();

        postJson('/api/locations', gpsLocationPayload($this->trip->id))
            ->assertNotFound()
            ->assertJsonPath('errors.0.status', '404');
    });
});

describe('the driver\'s GPS request never waits for the ETA', function () {
    beforeEach(function () {
        actingAsGatewayUser(ETA_TOKEN, role: 'driver');
        $this->trip = Trip::factory()->drivenBy(TEST_USER_ID)->create();
    });

    it('makes no call to the Dashboard nor the ETA service, it only keeps the token', function () {
        config(['services.eta.url' => ETA_SERVICE_URL, 'services.dashboard.url' => ETA_DASHBOARD_URL]);
        fakeEtaServices($this->trip->id);

        postJson('/api/locations', gpsLocationPayload($this->trip->id))->assertCreated();

        expect(etaRequests(ETA_DASHBOARD_URL))->toBeEmpty()
            ->and(etaRequests(ETA_SERVICE_URL))->toBeEmpty()
            ->and(Cache::has("gps:trip:{$this->trip->id}:eta:token"))->toBeTrue();
        Event::assertDispatched(BusLocationUpdated::class);
        Event::assertNotDispatched(TripArrivalsUpdated::class);
    });

    it('keeps nothing when the ETA is not configured', function () {
        config(['services.eta.url' => null, 'services.dashboard.url' => null]);

        postJson('/api/locations', gpsLocationPayload($this->trip->id))->assertCreated();

        expect(Cache::has("gps:trip:{$this->trip->id}:eta:token"))->toBeFalse();
    });
});

describe('gps:eta-watch', function () {
    beforeEach(function () {
        config(['services.eta.url' => ETA_SERVICE_URL, 'services.dashboard.url' => ETA_DASHBOARD_URL]);
        $this->trip = Trip::factory()->create();
        $this->eta = app(EtaOrchestrationService::class);
        $this->eta->rememberDriverToken($this->trip->id, ETA_TOKEN);
    });

    it('sends the ETA service exactly what it validates and broadcasts the arrivals', function () {
        fakeEtaServices($this->trip->id);
        etaBusAt($this->trip->id, 10.003); // ~333.6 m along the line

        $arrivals = $this->eta->refreshTrip($this->trip->id);

        // Dashboard context: driver's token, ETA projection.
        Http::assertSent(fn (ClientRequest $request) => str_starts_with($request->url(), ETA_DASHBOARD_URL."/api/internal/v1/trips/{$this->trip->id}/tracking-context")
            && $request['projection'] === 'eta-v1'
            && $request->hasHeader('Authorization', 'Bearer '.ETA_TOKEN));

        [$request] = etaRequests(ETA_SERVICE_URL)[0];
        expect($request->url())->toBe(ETA_SERVICE_URL."/trips/{$this->trip->id}/arrivals")
            ->and($request->hasHeader('Authorization', 'Bearer '.ETA_TOKEN))->toBeTrue();

        $body = $request->data();
        // The ETA service forbids extra fields.
        expect(array_keys($body))->toBe(['contract_version', 'route_revision', 'position_version', 'observed_at', 'tracking', 'targets'])
            ->and(array_keys($body['tracking']))->toBe([
                'on_route', 'accuracy_meters', 'current_speed_kmh', 'effective_speed_kmh',
                'effective_speed_observed_at', 'distance_along_route_m', 'confirmed_stop_occurrence_id',
            ])
            ->and($body['route_revision'])->toBe(ETA_REVISION)
            ->and($body['position_version'])->toBeInt()->toBeGreaterThan(0)
            ->and($body['tracking']['on_route'])->toBeTrue()
            ->and($body['tracking']['current_speed_kmh'])->toBe(36.0)
            ->and($body['tracking']['distance_along_route_m'])->toBeGreaterThan(330.0)->toBeLessThan(337.0);

        // Only the stops ahead, each exactly "stop distance - progress".
        $progress = $body['tracking']['distance_along_route_m'];
        expect(array_column($body['targets'], 'stop_occurrence_id'))->toBe([ETA_STOP_2, ETA_STOP_3])
            ->and(abs($body['targets'][0]['remaining_distance_m'] - (555.975 - $progress)))->toBeLessThan(0.001)
            ->and(abs($body['targets'][1]['remaining_distance_m'] - (1111.949 - $progress)))->toBeLessThan(0.001);

        expect($arrivals)->toHaveCount(2);
        Event::assertDispatched(TripArrivalsUpdated::class, fn (TripArrivalsUpdated $event) => $event->tripId === $this->trip->id
            && $event->arrivals[0]['occurrence_id'] === ETA_STOP_2
            && $event->arrivals[0]['eta_seconds'] === 23
            && $event->arrivals[0]['planned_arrival_at'] === '2026-11-13T18:01:00Z'
            && $event->arrivals[0]['method'] === 'distance_speed');
    });

    it('never reports progress beyond the route: at the last stop it is 0 m away', function () {
        fakeEtaServices($this->trip->id);
        etaBusAt($this->trip->id, 10.0102); // a bit past the end of the line

        $this->eta->refreshTrip($this->trip->id);

        $body = etaRequests(ETA_SERVICE_URL)[0][0]->data();
        expect($body['tracking']['distance_along_route_m'])->toBe(1111.949)
            ->and($body['targets'])->toBe([['stop_occurrence_id' => ETA_STOP_3, 'remaining_distance_m' => 0.0]]);
    });

    it('uses the last moving speed while the bus is stopped', function () {
        fakeEtaServices($this->trip->id);
        $movedAt = CarbonImmutable::now()->subSeconds(6);
        etaBusAt($this->trip->id, 10.003, 30.0, $movedAt);
        $this->eta->refreshTrip($this->trip->id);

        etaBusAt($this->trip->id, 10.003, 0.0);
        $this->eta->refreshTrip($this->trip->id);

        $tracking = etaRequests(ETA_SERVICE_URL)[1][0]->data()['tracking'];
        expect($tracking['current_speed_kmh'])->toBe(0.0)
            ->and($tracking['effective_speed_kmh'])->toBe(30.0)
            ->and($tracking['effective_speed_observed_at'])->toBe($movedAt->utc()->format('Y-m-d\TH:i:s.v\Z'));
    });

    it('sends no effective speed (nor its time) when the bus has not moved yet', function () {
        fakeEtaServices($this->trip->id);
        etaBusAt($this->trip->id, 10.0, 0.0);

        $this->eta->refreshTrip($this->trip->id);

        $tracking = etaRequests(ETA_SERVICE_URL)[0][0]->data()['tracking'];
        expect($tracking['effective_speed_kmh'])->toBeNull()
            ->and($tracking['effective_speed_observed_at'])->toBeNull();
    });

    it('estimates each position once and reads the context once', function () {
        fakeEtaServices($this->trip->id);
        etaBusAt($this->trip->id, 10.003);
        $this->eta->refreshTrip($this->trip->id);

        expect($this->eta->refreshTrip($this->trip->id))->toBeNull(); // same position

        etaBusAt($this->trip->id, 10.004);
        $this->eta->refreshTrip($this->trip->id);

        expect(etaRequests(ETA_DASHBOARD_URL))->toHaveCount(1)
            ->and(etaRequests(ETA_SERVICE_URL))->toHaveCount(2);
    });

    it('skips positions too old for the ETA service', function () {
        fakeEtaServices($this->trip->id);
        etaBusAt($this->trip->id, 10.003, at: CarbonImmutable::now()->subMinute());

        expect($this->eta->refreshTrip($this->trip->id))->toBeNull()
            ->and(Http::recorded())->toBeEmpty();
    });

    it('without a usable context it does not call the ETA service, and waits before asking again', function () {
        fakeEtaServices($this->trip->id, dashboard: Http::response(['errors' => [['status' => '422']]], 422));
        etaBusAt($this->trip->id, 10.003);
        $this->eta->refreshTrip($this->trip->id);

        etaBusAt($this->trip->id, 10.004);
        $this->eta->refreshTrip($this->trip->id);

        expect(etaRequests(ETA_DASHBOARD_URL))->toHaveCount(1)
            ->and(etaRequests(ETA_SERVICE_URL))->toBeEmpty();
        Event::assertNotDispatched(TripArrivalsUpdated::class);
    });

    it('reads the context again after a 409 (route revision changed)', function () {
        fakeEtaServices($this->trip->id, eta: Http::response(['errors' => [['status' => '409', 'code' => 'route_revision_mismatch']]], 409));
        etaBusAt($this->trip->id, 10.003);
        $this->eta->refreshTrip($this->trip->id);

        etaBusAt($this->trip->id, 10.004);
        $this->eta->refreshTrip($this->trip->id);

        expect(etaRequests(ETA_DASHBOARD_URL))->toHaveCount(2);
        Event::assertNotDispatched(TripArrivalsUpdated::class);
    });

    it('a failing ETA service only means no estimates', function () {
        fakeEtaServices($this->trip->id, eta: fn () => throw new RuntimeException('connection refused'));
        etaBusAt($this->trip->id, 10.003);

        expect($this->eta->refreshTrip($this->trip->id))->toBeNull();
        Event::assertNotDispatched(TripArrivalsUpdated::class);
    });

    it('does nothing for a trip without a recent driver token', function () {
        fakeEtaServices($this->trip->id);
        $other = Trip::factory()->create();
        etaBusAt($other->id, 10.003);

        expect($this->eta->refreshTrip($other->id))->toBeNull()
            ->and(Http::recorded())->toBeEmpty();
    });

    it('one pass of the command updates the trips in progress', function () {
        fakeEtaServices($this->trip->id);
        etaBusAt($this->trip->id, 10.003);

        artisan('gps:eta-watch', ['--once' => true])->assertExitCode(0);

        Event::assertDispatched(TripArrivalsUpdated::class, fn (TripArrivalsUpdated $event) => $event->tripId === $this->trip->id);
    });

    it('idles without calling anyone while the ETA is not configured', function () {
        config(['services.eta.url' => null]);
        Http::preventStrayRequests();
        etaBusAt($this->trip->id, 10.003);

        artisan('gps:eta-watch', ['--once' => true])->assertExitCode(0);

        expect(Http::recorded())->toBeEmpty();
        Event::assertNotDispatched(TripArrivalsUpdated::class);
    });
});
