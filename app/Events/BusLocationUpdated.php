<?php

namespace App\Events;

use App\Models\GpsLocation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired every time a new GPS coordinate of a trip is received (HU1) and
 * broadcast live for HU2 — whether or not it was also persisted to
 * `gps_locations` (see App\Services\GpsLocationService::record() and
 * config/gps.php: only the trip's start and end are stored; the readings in
 * between are broadcast from a transient, unsaved model, so the passenger's
 * live map gets every one of them).
 *
 * Origin      : App\Services\GpsLocationService::record()
 * Destination : private WebSocket channel of the trip -> Reverb -> Laravel Echo (Flutter)
 *
 * ShouldBroadcastNow: sent to Reverb inside the request, NOT through the
 * queue. A queued broadcast waits for the worker (`queue:work --sleep=3`,
 * one job at a time) and the passenger saw the bus seconds behind; Reverb
 * runs next to the API (127.0.0.1), so sending it inline takes milliseconds.
 *
 * The constructor extracts plain scalars from the model (simple payload).
 */
class BusLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** Null when this fix was broadcast but not persisted (see class docblock). */
    public ?string $locationId;

    public string $tripId;

    public string $busId;

    public float $latitude;

    public float $longitude;

    public ?float $speedKmh;

    public ?float $accuracyMeters;

    /** ISO-8601 in UTC, e.g. "2026-09-03T15:00:00.000000Z". */
    public string $recordedAt;

    public function __construct(GpsLocation $location)
    {
        // bus_id is not a column on gps_locations; it comes from the trip.
        $location->loadMissing('trip');

        // Unsaved (transient) model: this fix was broadcast but not persisted.
        $this->locationId = $location->exists ? $location->id : null;
        $this->tripId = $location->trip_id;
        $this->busId = $location->trip->bus_id;
        $this->latitude = (float) $location->latitude;
        $this->longitude = (float) $location->longitude;
        $this->speedKmh = $location->speed_kmh !== null ? (float) $location->speed_kmh : null;
        $this->accuracyMeters = $location->accuracy_meters !== null ? (float) $location->accuracy_meters : null;
        $this->recordedAt = $location->recorded_at->toISOString();
    }

    /**
     * Private channel of the trip. Subscriptions are authorized in
     * routes/channels.php via POST /api/gps/broadcasting/auth.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('trip.'.$this->tripId),
        ];
    }

    /**
     * Event name the client listens for: `.listen('.BusLocationUpdated', ...)`.
     */
    public function broadcastAs(): string
    {
        return 'BusLocationUpdated';
    }

    /**
     * Payload the Flutter client uses to move the bus marker on the map.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->locationId,
            'trip_id' => $this->tripId,
            'bus_id' => $this->busId,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'speed_kmh' => $this->speedKmh,
            'accuracy_meters' => $this->accuracyMeters,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
