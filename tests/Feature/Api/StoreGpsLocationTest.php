<?php

use App\Events\BusLocationUpdated;
use App\Models\GpsLocation;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

use function Pest\Laravel\postJson;

/*
| HU1 — "As a system, I need to receive the GPS coordinates sent by the driver's
| app so that I can determine the bus's current location."
|
| Every valid reading is ACCEPTED and BROADCAST live (HU2), but it is only
| PERSISTED to gps_locations when it is the trip's first reading, or it moved
| far enough, or enough time passed since the last persisted one
| (config/gps.php, App\Services\GpsLocationService).
*/

const STORE_URL = '/api/locations';

beforeEach(function () {
    // Broadcasting is asserted explicitly; no test depends on Reverb running.
    Event::fake([BusLocationUpdated::class]);

    $this->trip = Trip::factory()->create();
});

it('stores a valid GPS reading and returns a JSON:API resource', function () {
    postJson(STORE_URL, gpsLocationPayload($this->trip->id))
        ->assertCreated()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonStructure(['data' => ['id', 'type', 'attributes' => ['trip_id', 'latitude', 'longitude', 'speed_kmh', 'recorded_at']]])
        ->assertJsonPath('data.type', 'gps-locations')
        ->assertJsonPath('data.attributes.trip_id', $this->trip->id)
        ->assertJsonPath('data.attributes.latitude', 9.93)
        ->assertJsonPath('data.attributes.longitude', -84.08)
        ->assertJsonPath('data.attributes.speed_kmh', 41.2)
        ->assertJsonPath('data.attributes.recorded_at', '2026-09-07T12:00:00.000000Z');

    expect(GpsLocation::query()->where('trip_id', $this->trip->id)->count())->toBe(1);
});

it('never exposes the internal PostGIS column in the response', function () {
    postJson(STORE_URL, gpsLocationPayload($this->trip->id))
        ->assertCreated()
        ->assertJsonMissingPath('data.attributes.location');
});

it('derives the PostGIS point with (lng, lat) order and SRID 4326', function () {
    $id = postJson(STORE_URL, gpsLocationPayload($this->trip->id))->json('data.id');

    $point = DB::selectOne(
        'select ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng, ST_SRID(location::geometry) as srid
         from gps_locations where id = ?',
        [$id],
    );

    expect((float) $point->lat)->toEqualWithDelta(9.93, 1e-7)
        ->and((float) $point->lng)->toEqualWithDelta(-84.08, 1e-7)
        ->and((int) $point->srid)->toBe(4326);
});

it('broadcasts BusLocationUpdated on the private trip channel', function () {
    postJson(STORE_URL, gpsLocationPayload($this->trip->id))->assertCreated();

    Event::assertDispatched(BusLocationUpdated::class, function (BusLocationUpdated $event) {
        $payload = $event->broadcastWith();

        return collect($event->broadcastOn())->map->name->all() === ["private-trip.{$this->trip->id}"]
            && $event->broadcastAs() === 'BusLocationUpdated'
            && $payload['trip_id'] === $this->trip->id
            && $payload['bus_id'] === $this->trip->bus_id
            && $payload['latitude'] === 9.93
            && $payload['longitude'] === -84.08;
    });
});

describe('validation', function () {
    it('rejects an invalid attribute with a JSON:API error pointing to it', function (array $attributes, string $pointer) {
        postJson(STORE_URL, gpsLocationPayload($this->trip->id, $attributes))
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonPath('errors.0.status', '422')
            ->assertJsonPath('errors.0.source.pointer', $pointer);
    })->with([
        'latitude out of range' => [['latitude' => 999], '/data/attributes/latitude'],
        'longitude out of range' => [['longitude' => -181], '/data/attributes/longitude'],
        'negative speed' => [['speed_kmh' => -1], '/data/attributes/speed_kmh'],
        'recorded_at not a date' => [['recorded_at' => 'yesterday-ish'], '/data/attributes/recorded_at'],
        // Must be a clean 422, not a Postgres "invalid input syntax for type uuid" 500.
        'trip_id not a UUID' => [['trip_id' => 1], '/data/attributes/trip_id'],
    ]);

    it('rejects a missing longitude', function () {
        $payload = gpsLocationPayload($this->trip->id);
        unset($payload['data']['attributes']['longitude']);

        postJson(STORE_URL, $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/longitude');
    });

    it('rejects a trip that does not exist', function () {
        postJson(STORE_URL, gpsLocationPayload((string) Str::uuid7()))
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/trip_id');
    });

    it('rejects a document with the wrong JSON:API type', function () {
        $payload = gpsLocationPayload($this->trip->id);
        $payload['data']['type'] = 'locations';

        postJson(STORE_URL, $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.source.pointer', '/data/type');
    });
});

describe('persist filter (transmit != persist)', function () {
    it('does not persist but still broadcasts when close in distance and time', function () {
        postJson(STORE_URL, gpsLocationPayload($this->trip->id, ['recorded_at' => '2026-09-07T12:00:00Z']))
            ->assertCreated();

        // ~3 m away, 10 s later: below the default 15 m / 60 s thresholds.
        postJson(STORE_URL, gpsLocationPayload($this->trip->id, [
            'latitude' => 9.93003,
            'recorded_at' => '2026-09-07T12:00:10Z',
        ]))
            ->assertAccepted()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonMissingPath('data')
            ->assertJsonPath('meta.persisted', false);

        expect(GpsLocation::query()->where('trip_id', $this->trip->id)->count())->toBe(1);

        // HU2 still fires for it: the live map must not stall.
        Event::assertDispatched(BusLocationUpdated::class, 2);
        Event::assertDispatched(BusLocationUpdated::class, fn (BusLocationUpdated $event) => $event->locationId === null);
    });

    it('persists when it moved far enough even if soon after', function () {
        postJson(STORE_URL, gpsLocationPayload($this->trip->id, ['recorded_at' => '2026-09-07T12:00:00Z']))
            ->assertCreated();

        // ~33 m away (> 15 m), only 10 s later (< 60 s): distance alone is enough.
        postJson(STORE_URL, gpsLocationPayload($this->trip->id, [
            'latitude' => 9.9303,
            'recorded_at' => '2026-09-07T12:00:10Z',
        ]))->assertCreated();

        expect(GpsLocation::query()->where('trip_id', $this->trip->id)->count())->toBe(2);
    });

    it('persists when enough time passed even without moving', function () {
        postJson(STORE_URL, gpsLocationPayload($this->trip->id, ['recorded_at' => '2026-09-07T12:00:00Z']))
            ->assertCreated();

        // Same coordinates, 90 s later (> 60 s): a stopped bus still leaves a periodic trail.
        postJson(STORE_URL, gpsLocationPayload($this->trip->id, ['recorded_at' => '2026-09-07T12:01:30Z']))
            ->assertCreated();

        expect(GpsLocation::query()->where('trip_id', $this->trip->id)->count())->toBe(2);
    });
});
