<?php

use App\Events\BusLocationUpdated;
use App\Events\TripTrackingEnded;
use App\Models\Trip;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
| "Iniciar / Terminar recorrido": the driver assigned to a trip starts it
| (scheduled -> in_progress) and finishes it (in_progress -> completed).
*/

beforeEach(function () {
    Event::fake([BusLocationUpdated::class, TripTrackingEnded::class]);

    actingAsGatewayUser('driver-token', role: 'driver');
});

describe('start', function () {
    it('lets the assigned driver start a scheduled trip', function () {
        $trip = Trip::factory()->drivenBy(TEST_USER_ID)->scheduled()->create();

        postJson("/api/trips/{$trip->id}/start")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonPath('data.type', 'trips')
            ->assertJsonPath('data.id', $trip->id)
            ->assertJsonPath('data.attributes.status', 'in_progress');

        $trip->refresh();
        expect($trip->status)->toBe(Trip::STATUS_IN_PROGRESS)
            ->and($trip->actual_departure_at)->not->toBeNull();
    });

    it('then accepts the driver\'s GPS readings', function () {
        $trip = Trip::factory()->drivenBy(TEST_USER_ID)->scheduled()->create();

        postJson('/api/locations', gpsLocationPayload($trip->id))->assertConflict();

        postJson("/api/trips/{$trip->id}/start")->assertOk();

        postJson('/api/locations', gpsLocationPayload($trip->id))->assertCreated();
    });

    it('rejects a driver who is not assigned to the trip', function () {
        $trip = Trip::factory()->scheduled()->create();

        postJson("/api/trips/{$trip->id}/start")
            ->assertForbidden()
            ->assertJsonPath('errors.0.status', '403');

        expect($trip->refresh()->status)->toBe(Trip::STATUS_SCHEDULED);
    });

    it('rejects starting a trip that is not scheduled', function (string $state) {
        $trip = Trip::factory()->drivenBy(TEST_USER_ID)->{$state}()->create();

        postJson("/api/trips/{$trip->id}/start")
            ->assertConflict()
            ->assertJsonPath('errors.0.status', '409');
    })->with(['completed', 'cancelled']);

    it('rejects starting a trip already in progress', function () {
        $trip = Trip::factory()->drivenBy(TEST_USER_ID)->create();

        postJson("/api/trips/{$trip->id}/start")->assertConflict();
    });
});

describe('finish', function () {
    it('lets the assigned driver finish the trip and notifies the passengers', function () {
        $trip = Trip::factory()->drivenBy(TEST_USER_ID)->create();

        postJson("/api/trips/{$trip->id}/finish")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', 'completed');

        $trip->refresh();
        expect($trip->status)->toBe(Trip::STATUS_COMPLETED)
            ->and($trip->actual_arrival_at)->not->toBeNull();

        Event::assertDispatched(TripTrackingEnded::class, function (TripTrackingEnded $event) use ($trip) {
            return collect($event->broadcastOn())->map->name->all() === ["private-trip.{$trip->id}"]
                && $event->broadcastAs() === 'TripTrackingEnded'
                && $event->broadcastWith()['trip_id'] === $trip->id
                && $event->broadcastWith()['status'] === 'completed';
        });
    });

    it('then rejects new GPS readings and shows the trip as not tracking', function () {
        $trip = Trip::factory()->drivenBy(TEST_USER_ID)->create();
        postJson('/api/locations', gpsLocationPayload($trip->id))->assertCreated();

        postJson("/api/trips/{$trip->id}/finish")->assertOk();

        postJson('/api/locations', gpsLocationPayload($trip->id, [
            'recorded_at' => '2026-09-07T12:10:00Z',
        ]))->assertConflict();

        getJson("/api/trips/{$trip->id}/location")
            ->assertJsonPath('data.attributes.status', 'completed')
            ->assertJsonPath('data.attributes.freshness', 'not_tracking');
    });

    it('rejects a driver who is not assigned to the trip', function () {
        $trip = Trip::factory()->create();

        postJson("/api/trips/{$trip->id}/finish")->assertForbidden();

        expect($trip->refresh()->status)->toBe(Trip::STATUS_IN_PROGRESS);
        Event::assertNotDispatched(TripTrackingEnded::class);
    });

    it('rejects finishing a trip that is not in progress', function (string $state) {
        $trip = Trip::factory()->drivenBy(TEST_USER_ID)->{$state}()->create();

        postJson("/api/trips/{$trip->id}/finish")->assertConflict();
    })->with(['scheduled', 'completed', 'cancelled']);
});

it('requires a token', function (string $action) {
    $trip = Trip::factory()->drivenBy(TEST_USER_ID)->scheduled()->create();
    $this->defaultHeaders = [];

    postJson("/api/trips/{$trip->id}/{$action}")->assertUnauthorized();
})->with(['start', 'finish']);

it('returns 404 for a trip that does not exist or is not a UUID', function (string $tripId) {
    postJson("/api/trips/{$tripId}/start")->assertNotFound();
})->with([
    'unknown trip' => fn () => (string) Str::uuid7(),
    'not a UUID' => 'abc',
]);
