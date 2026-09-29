<?php

use App\Models\GpsLocation;
use App\Models\Trip;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;

/*
| HU3 — latest known position of a trip (initial state of the passenger map,
| before the next BusLocationUpdated event arrives).
*/

it('returns the most recent reading of the trip as a JSON:API resource', function () {
    $trip = Trip::factory()->create();

    GpsLocation::factory()->for($trip)->create(['recorded_at' => '2026-09-07 12:00:00']);
    $latest = GpsLocation::factory()->for($trip)->create([
        'latitude' => 9.95,
        'longitude' => -84.05,
        'recorded_at' => '2026-09-07 12:05:00',
    ]);

    getJson("/api/trips/{$trip->id}/location")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonPath('data.type', 'gps-locations')
        ->assertJsonPath('data.id', $latest->id)
        ->assertJsonPath('data.attributes.trip_id', $trip->id)
        ->assertJsonPath('data.attributes.latitude', 9.95)
        ->assertJsonPath('data.attributes.longitude', -84.05);
});

it('includes the trip when requested with ?include=trip', function () {
    $location = GpsLocation::factory()->create();

    getJson("/api/trips/{$location->trip_id}/location?include=trip")
        ->assertOk()
        ->assertJsonPath('data.relationships.trip.data.id', $location->trip_id)
        ->assertJsonPath('included.0.type', 'trips')
        ->assertJsonPath('included.0.attributes.status', 'in_progress');
});

it('returns a JSON:API 404 when the trip has no readings', function () {
    $trip = Trip::factory()->create();

    getJson("/api/trips/{$trip->id}/location")
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonPath('errors.0.status', '404');
});

it('returns 404 for a trip that does not exist', function () {
    getJson('/api/trips/'.Str::uuid7().'/location')->assertNotFound();
});

it('returns 404 (not a 500) for a trip id that is not a UUID', function () {
    getJson('/api/trips/not-a-uuid/location')->assertNotFound();
});
