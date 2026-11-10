<?php

use App\Events\BusLocationUpdated;
use App\Models\GpsLocation;
use App\Models\Trip;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

/*
| Current position of a trip: what the passenger's map loads when it opens (or
| reconnects) before the next live BusLocationUpdated event arrives.
*/

beforeEach(function () {
    Event::fake([BusLocationUpdated::class]);

    // A passenger's token, recognized by the (fake) API Gateway.
    actingAsGatewayUser('passenger-token', role: 'passenger');
});

it('rejects a request without a token with a JSON:API 401', function () {
    $trip = Trip::factory()->create();
    $this->defaultHeaders = []; // drop the passenger's token

    getJson("/api/trips/{$trip->id}/location")
        ->assertUnauthorized()
        ->assertJsonPath('errors.0.status', '401');
});

it('returns the latest reading as a live position', function () {
    travelTo('2026-09-07 12:05:10');
    $trip = Trip::factory()->create();
    GpsLocation::factory()->for($trip)->create(['recorded_at' => '2026-09-07 12:00:00']);
    GpsLocation::factory()->for($trip)->create([
        'latitude' => 9.95,
        'longitude' => -84.05,
        'speed_kmh' => 30,
        'accuracy_meters' => 6,
        'recorded_at' => '2026-09-07 12:05:00',
    ]);

    getJson("/api/trips/{$trip->id}/location")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonPath('data.type', 'trip-trackings')
        ->assertJsonPath('data.id', $trip->id)
        ->assertJsonPath('data.attributes.status', 'in_progress')
        ->assertJsonPath('data.attributes.freshness', 'live')
        ->assertJsonPath('data.attributes.age_seconds', 10)
        ->assertJsonPath('data.attributes.position.latitude', 9.95)
        ->assertJsonPath('data.attributes.position.longitude', -84.05)
        ->assertJsonPath('data.attributes.position.accuracy_meters', 6)
        ->assertJsonPath('data.attributes.position.recorded_at', '2026-09-07T12:05:00.000000Z');
});

it('returns the freshest reading even when it was not stored as a sample', function () {
    // The token's user (TEST_USER_ID) is the driver of this trip.
    $trip = Trip::factory()->drivenBy(TEST_USER_ID)->create();

    postJson('/api/locations', gpsLocationPayload($trip->id, ['recorded_at' => now()->subSeconds(20)->toISOString()]))
        ->assertCreated();
    // 3 m away, 10 s later: broadcast but not stored (below the sampling threshold).
    postJson('/api/locations', gpsLocationPayload($trip->id, [
        'latitude' => 9.93003,
        'recorded_at' => now()->subSeconds(10)->toISOString(),
    ]))->assertAccepted();

    getJson("/api/trips/{$trip->id}/location")
        ->assertOk()
        ->assertJsonPath('data.attributes.position.latitude', 9.93003)
        ->assertJsonPath('data.attributes.freshness', 'live');

    expect(GpsLocation::query()->count())->toBe(1);
});

it('reports the freshness of the position', function (int $ageSeconds, string $freshness) {
    travelTo('2026-09-07 13:00:00');
    $trip = Trip::factory()->create();
    GpsLocation::factory()->for($trip)->create([
        'recorded_at' => now()->subSeconds($ageSeconds),
    ]);

    getJson("/api/trips/{$trip->id}/location")
        ->assertOk()
        ->assertJsonPath('data.attributes.freshness', $freshness)
        ->assertJsonPath('data.attributes.age_seconds', $ageSeconds);
})->with([
    'live (30 s)' => [30, 'live'],
    'stale (2 min)' => [120, 'stale'],
    'no signal (10 min)' => [600, 'no_signal'],
]);

it('reports no signal while the bus has not sent any reading yet', function () {
    $trip = Trip::factory()->create();

    getJson("/api/trips/{$trip->id}/location")
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'in_progress')
        ->assertJsonPath('data.attributes.freshness', 'no_signal')
        ->assertJsonPath('data.attributes.age_seconds', null)
        ->assertJsonPath('data.attributes.position', null);
});

it('reports not_tracking for a trip that is not running', function (string $state) {
    $trip = Trip::factory()->{$state}()->create();

    getJson("/api/trips/{$trip->id}/location")
        ->assertOk()
        ->assertJsonPath('data.attributes.status', $trip->status)
        ->assertJsonPath('data.attributes.freshness', 'not_tracking');
})->with(['scheduled', 'completed', 'cancelled']);

it('returns 404 for a trip that does not exist', function () {
    getJson('/api/trips/'.Str::uuid7().'/location')
        ->assertNotFound()
        ->assertJsonPath('errors.0.status', '404');
});

it('returns 404 for a trip deleted by the Dashboard', function () {
    $trip = Trip::factory()->create();
    $trip->delete(); // soft delete (trips.deleted_at)

    getJson("/api/trips/{$trip->id}/location")
        ->assertNotFound()
        ->assertJsonPath('errors.0.status', '404');
});

it('returns 404 (not a 500) for a trip id that is not a UUID', function () {
    getJson('/api/trips/not-a-uuid/location')->assertNotFound();
});
