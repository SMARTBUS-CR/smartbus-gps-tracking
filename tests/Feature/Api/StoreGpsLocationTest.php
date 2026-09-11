<?php

namespace Tests\Feature\Api;

use App\Events\BusLocationUpdated;
use App\Models\GpsLocation;
use App\Models\Trip;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * HU1 — "As a system, I need to receive the GPS coordinates sent by the driver's
 * app so that I can determine the bus's current location."
 *
 * Every valid reading is ACCEPTED and BROADCAST live (HU2) — but it is only
 * PERSISTED to gps_locations when it's the trip's first reading, or it moved
 * far enough, or enough time passed since the last one that was persisted
 * (config/gps.php, App\Services\GpsLocationService). This keeps the historical
 * table from growing unboundedly with redundant points of a stopped bus,
 * without ever stalling the passenger's live map.
 *
 * No migrations: the real operations DB (pgsql from .env) is used and every test
 * runs inside a transaction that is rolled back (DatabaseTransactions). setUp()
 * clears any pre-existing gps_locations of the trip under test so the
 * persist/skip decision in every test is deterministic and does not depend on
 * whatever real data happens to be sitting in the dev DB.
 */
class StoreGpsLocationTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/locations';

    private const BASE_LAT = 9.9300000;

    private const BASE_LNG = -84.0800000;

    private int $tripId;

    protected function setUp(): void
    {
        parent::setUp();

        // Broadcasting is covered by its own tests below; most do not depend on Reverb.
        Event::fake([BusLocationUpdated::class]);

        $tripId = Trip::query()->value('id');
        if ($tripId === null) {
            $this->markTestSkipped('The operations DB has no trips to test against.');
        }
        $this->tripId = (int) $tripId;

        // Determinism: every test starts as if this trip had no prior readings,
        // regardless of what real data exists in the dev DB.
        GpsLocation::query()->where('trip_id', $this->tripId)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $attributes = []): array
    {
        return [
            'data' => [
                'type' => 'gps-locations',
                'attributes' => array_merge([
                    'trip_id' => $this->tripId,
                    'latitude' => self::BASE_LAT,
                    'longitude' => self::BASE_LNG,
                    'speed_kmh' => 41.2,
                    'recorded_at' => '2026-09-07T12:00:00Z',
                ], $attributes),
            ],
        ];
    }

    private function countForTrip(): int
    {
        return GpsLocation::query()->where('trip_id', $this->tripId)->count();
    }

    public function test_stores_a_valid_gps_reading_and_returns_a_json_api_resource(): void
    {
        // First reading of the trip: always persisted, regardless of the filter.
        $response = $this->postJson(self::URL, $this->payload());

        $response->assertCreated()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonPath('data.type', 'gps-locations')
            ->assertJsonPath('data.attributes.trip_id', $this->tripId)
            ->assertJsonPath('data.attributes.latitude', 9.93)
            ->assertJsonPath('data.attributes.longitude', -84.08)
            ->assertJsonPath('data.attributes.speed_kmh', 41.2)
            ->assertJsonPath('data.attributes.recorded_at', '2026-09-07T12:00:00.000000Z');

        $this->assertSame(1, $this->countForTrip());
    }

    public function test_derives_the_postgis_point_with_lng_lat_order_and_srid_4326(): void
    {
        $id = (int) $this->postJson(self::URL, $this->payload())->json('data.id');

        $point = DB::selectOne(
            'select ST_Y(location::geometry) as lat,
                    ST_X(location::geometry) as lng,
                    ST_SRID(location::geometry) as srid
             from gps_locations where id = ?',
            [$id],
        );

        $this->assertEqualsWithDelta(9.93, (float) $point->lat, 1e-7);
        $this->assertEqualsWithDelta(-84.08, (float) $point->lng, 1e-7);
        $this->assertSame(4326, (int) $point->srid);
    }

    public function test_rejects_a_latitude_out_of_range_with_a_json_api_error(): void
    {
        $response = $this->postJson(self::URL, $this->payload(['latitude' => 999]));

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonPath('errors.0.status', '422')
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/latitude');
    }

    public function test_rejects_a_missing_longitude(): void
    {
        $payload = $this->payload();
        unset($payload['data']['attributes']['longitude']);

        $this->postJson(self::URL, $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/longitude');
    }

    public function test_rejects_a_trip_id_that_does_not_exist(): void
    {
        $this->postJson(self::URL, $this->payload(['trip_id' => 999_999_999]))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/trip_id');
    }

    public function test_broadcasts_bus_location_updated_on_the_private_trip_channel(): void
    {
        $this->postJson(self::URL, $this->payload())->assertCreated();

        Event::assertDispatched(BusLocationUpdated::class, function (BusLocationUpdated $event) {
            $channels = collect($event->broadcastOn())->map->name->all();
            $payload = $event->broadcastWith();

            return $channels === ["private-trip.{$this->tripId}"]
                && $event->broadcastAs() === 'BusLocationUpdated'
                && $payload['trip_id'] === $this->tripId
                && $payload['latitude'] === 9.93
                && $payload['longitude'] === -84.08
                && array_key_exists('bus_id', $payload)
                && array_key_exists('recorded_at', $payload);
        });
    }

    // --- Persist filter: transmit != persist (config/gps.php) -------------

    public function test_does_not_persist_but_still_broadcasts_when_close_in_distance_and_time(): void
    {
        // Baseline reading (persisted: it's the first one).
        $this->postJson(self::URL, $this->payload(['recorded_at' => '2026-09-07T12:00:00Z']))
            ->assertCreated();
        $this->assertSame(1, $this->countForTrip());

        // ~3 m away, 10 s later: below both the default 15 m / 60 s thresholds.
        $response = $this->postJson(self::URL, $this->payload([
            'latitude' => self::BASE_LAT + 0.00003,
            'recorded_at' => '2026-09-07T12:00:10Z',
        ]));

        $response->assertAccepted()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonMissingPath('data')
            ->assertJsonPath('meta.persisted', false);

        // Still just the one row — the second fix was not worth persisting.
        $this->assertSame(1, $this->countForTrip());

        // But HU2 still fires for it (live map must not stall).
        Event::assertDispatched(BusLocationUpdated::class, 2);
        Event::assertDispatched(
            BusLocationUpdated::class,
            fn (BusLocationUpdated $event) => $event->locationId === null
                && $event->tripId === $this->tripId
                && $event->latitude === self::BASE_LAT + 0.00003,
        );
    }

    public function test_persists_when_it_moved_far_enough_even_if_soon_after(): void
    {
        $this->postJson(self::URL, $this->payload(['recorded_at' => '2026-09-07T12:00:00Z']))
            ->assertCreated();

        // ~33 m away (> 15 m threshold), only 10 s later (< 60 s threshold):
        // distance alone is enough to persist.
        $this->postJson(self::URL, $this->payload([
            'latitude' => self::BASE_LAT + 0.0003,
            'recorded_at' => '2026-09-07T12:00:10Z',
        ]))->assertCreated();

        $this->assertSame(2, $this->countForTrip());
    }

    public function test_persists_when_enough_time_passed_even_without_moving(): void
    {
        $this->postJson(self::URL, $this->payload(['recorded_at' => '2026-09-07T12:00:00Z']))
            ->assertCreated();

        // Same exact coordinates, 90 s later (> 60 s threshold): time alone
        // is enough — a stopped bus still leaves a periodic trail.
        $this->postJson(self::URL, $this->payload(['recorded_at' => '2026-09-07T12:01:30Z']))
            ->assertCreated();

        $this->assertSame(2, $this->countForTrip());
    }
}
