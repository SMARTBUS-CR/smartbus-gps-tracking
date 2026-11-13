<?php

use App\Events\BusLocationUpdated;
use App\Events\TripArrivalsUpdated;
use App\Jobs\CalculateTripArrivalsJob;
use App\Models\Driver;
use App\Models\Trip;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\postJson;

const GPS_STORE_URL = '/api/locations';
const TEST_TOKEN = 'driver-test-bearer-token';

beforeEach(function () {
    Event::fake([BusLocationUpdated::class, TripArrivalsUpdated::class]);

    actingAsGatewayUser(TEST_TOKEN, role: 'driver');

    $this->trip = Trip::factory()->drivenBy(TEST_USER_ID)->create([
        'planned_route_snapshot' => [
            'type' => 'LineString',
            'coordinates' => [
                [-84.0907, 9.9281],
                [-84.1165, 9.9981],
            ],
            'revision_hash' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90',
            'occurrences' => [
                [
                    'occurrence_id' => '01a0c691-1111-731c-a034-03cc8fe8136f',
                    'stop_id' => '01a0c691-2222-731c-a034-03cc8fe8136f',
                    'stop_sequence' => 1,
                    'lat' => 9.9281,
                    'lng' => -84.0907,
                ],
                [
                    'occurrence_id' => '01a0c691-3333-731c-a034-03cc8fe8136f',
                    'stop_id' => '01a0c691-4444-731c-a034-03cc8fe8136f',
                    'stop_sequence' => 2,
                    'lat' => 9.9981,
                    'lng' => -84.1165,
                ],
            ],
        ],
    ]);
});

describe('GPS-02 driver assignment validation', function () {
    it('aborts 403 forbidden when user is not the driver assigned to the trip', function () {
        $otherTrip = Trip::factory()->create();

        postJson(GPS_STORE_URL, gpsLocationPayload($otherTrip->id))
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonPath('errors.0.status', '403')
            ->assertJsonPath('errors.0.detail', 'The authenticated user is not the assigned driver for this trip');

        Event::assertNotDispatched(BusLocationUpdated::class);
        Event::assertNotDispatched(TripArrivalsUpdated::class);
    });

    it('aborts 403 forbidden when the assigned driver is inactive', function () {
        $this->trip->driver->forceFill(['status' => Driver::STATUS_ACTIVE === 'active' ? 'inactive' : 'disabled'])->save();

        postJson(GPS_STORE_URL, gpsLocationPayload($this->trip->id))
            ->assertForbidden()
            ->assertJsonPath('errors.0.status', '403')
            ->assertJsonPath('errors.0.detail', 'The authenticated user is not the assigned driver for this trip');
    });

    it('aborts 409 conflict when the trip is not currently in progress', function (string $status) {
        $trip = Trip::factory()->drivenBy(TEST_USER_ID)->{$status}()->create();

        postJson(GPS_STORE_URL, gpsLocationPayload($trip->id))
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonPath('errors.0.status', '409')
            ->assertJsonPath('errors.0.detail', 'Trip is not currently in progress');
    })->with(['scheduled', 'completed', 'cancelled']);

    it('aborts 404 not found when the trip does not exist', function () {
        $this->trip->delete();

        postJson(GPS_STORE_URL, gpsLocationPayload($this->trip->id))
            ->assertNotFound()
            ->assertJsonPath('errors.0.status', '404');
    });
});

describe('ETA orchestration and dispatch', function () {
    it('dispatches CalculateTripArrivalsJob with bearer token on accepted GPS reading', function () {
        Queue::fake([CalculateTripArrivalsJob::class]);

        postJson(GPS_STORE_URL, gpsLocationPayload($this->trip->id))
            ->assertCreated();

        Queue::assertPushed(CalculateTripArrivalsJob::class, function (CalculateTripArrivalsJob $job) {
            return $job->tripId === $this->trip->id
                && $job->bearerToken === TEST_TOKEN
                && $job->fix->tripId === $this->trip->id;
        });
    });

    it('successfully calls ETA microservice and broadcasts TripArrivalsUpdated', function () {
        $etaUrl = rtrim(config('services.eta.url', 'http://eta-service:8000/api/v1/eta'), '/')."/trips/{$this->trip->id}/arrivals";

        $mockArrivals = [
            [
                'stop_occurrence_id' => '01a0c691-1111-731c-a034-03cc8fe8136f',
                'stop_id' => '01a0c691-2222-731c-a034-03cc8fe8136f',
                'stop_sequence' => 1,
                'remaining_distance_m' => 450.5,
                'eta_seconds' => 90,
                'arrival_at' => '2026-09-07T12:01:30Z',
                'method' => 'distance_speed',
            ],
            [
                'stop_occurrence_id' => '01a0c691-3333-731c-a034-03cc8fe8136f',
                'stop_id' => '01a0c691-4444-731c-a034-03cc8fe8136f',
                'stop_sequence' => 2,
                'remaining_distance_m' => 1250.0,
                'eta_seconds' => 210,
                'arrival_at' => '2026-09-07T12:03:30Z',
                'method' => 'distance_speed',
            ],
        ];

        $GLOBALS['custom_eta_response'] = Http::response([
            'data' => [
                'type' => 'trip-arrival-estimates',
                'id' => $this->trip->id,
                'attributes' => [
                    'arrivals' => $mockArrivals,
                ],
            ],
        ], 200);

        postJson(GPS_STORE_URL, gpsLocationPayload($this->trip->id))
            ->assertCreated();

        Http::assertSent(function (ClientRequest $request) use ($etaUrl) {
            if ($request->url() !== $etaUrl) {
                return false;
            }

            $hasAuth = $request->hasHeader('Authorization', 'Bearer '.TEST_TOKEN);
            $data = $request->data();

            $hasRevision = isset($data['route_revision'])
                && $data['route_revision'] === 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
            $hasObservedAt = isset($data['observed_at']);
            $hasContract = ($data['contract_version'] ?? null) === 1;
            $hasTracking = isset($data['tracking'])
                && is_array($data['tracking'])
                && array_key_exists('on_route', $data['tracking'])
                && array_key_exists('distance_along_route_m', $data['tracking'])
                && array_key_exists('current_speed_kmh', $data['tracking'])
                && array_key_exists('effective_speed_kmh', $data['tracking']);
            $hasTargets = isset($data['targets']) && is_array($data['targets']) && count($data['targets']) > 0;

            return $hasAuth && $hasRevision && $hasObservedAt && $hasContract && $hasTracking && $hasTargets;
        });

        Event::assertDispatched(TripArrivalsUpdated::class, function (TripArrivalsUpdated $event) {
            return $event->tripId === $this->trip->id
                && count($event->arrivals) === 2
                && $event->arrivals[0]['occurrence_id'] === '01a0c691-1111-731c-a034-03cc8fe8136f'
                && $event->arrivals[0]['eta_seconds'] === 90
                && $event->broadcastAs() === 'TripArrivalsUpdated'
                && collect($event->broadcastOn())->map->name->all() === ["private-trip.{$this->trip->id}"];
        });
    });

    it('gracefully handles ETA microservice timeout or failure without breaking GPS ingestion', function () {
        $GLOBALS['custom_eta_response'] = Http::response(['errors' => ['ETA service temporary outage']], 500);

        // Ingestion should still succeed with 201 Created and broadcast BusLocationUpdated
        postJson(GPS_STORE_URL, gpsLocationPayload($this->trip->id))
            ->assertCreated();

        Event::assertDispatched(BusLocationUpdated::class);
        Event::assertNotDispatched(TripArrivalsUpdated::class);
    });
});
