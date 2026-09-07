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
 * No migrations: the real operations DB (pgsql from .env) is used and every test
 * runs inside a transaction that is rolled back (DatabaseTransactions).
 */
class StoreGpsLocationTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/locations';

    private int $tripId;

    protected function setUp(): void
    {
        parent::setUp();

        // Broadcasting is covered by its own test; the rest do not depend on Reverb.
        Event::fake([BusLocationUpdated::class]);

        $tripId = Trip::query()->value('id');
        if ($tripId === null) {
            $this->markTestSkipped('The operations DB has no trips to test against.');
        }
        $this->tripId = (int) $tripId;
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
                    'latitude' => 9.9300000,
                    'longitude' => -84.0800000,
                    'speed_kmh' => 41.2,
                    'recorded_at' => '2026-09-07T12:00:00Z',
                ], $attributes),
            ],
        ];
    }

    public function test_stores_a_valid_gps_reading_and_returns_a_json_api_resource(): void
    {
        $before = GpsLocation::query()->where('trip_id', $this->tripId)->count();

        $response = $this->postJson(self::URL, $this->payload());

        $response->assertCreated()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonPath('data.type', 'gps-locations')
            ->assertJsonPath('data.attributes.trip_id', $this->tripId)
            ->assertJsonPath('data.attributes.latitude', 9.93)
            ->assertJsonPath('data.attributes.longitude', -84.08)
            ->assertJsonPath('data.attributes.speed_kmh', 41.2)
            ->assertJsonPath('data.attributes.recorded_at', '2026-09-07T12:00:00.000000Z');

        $this->assertSame(
            $before + 1,
            GpsLocation::query()->where('trip_id', $this->tripId)->count(),
        );
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
}
