<?php

namespace App\Events;

use App\Models\GpsLocation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired every time a new GPS coordinate of a trip is received (HU1) and
 * broadcast live for HU2 — whether or not it was also persisted to
 * `gps_locations` (see App\Services\GpsLocationService::record() and
 * config/gps.php: readings below the distance/time threshold are still
 * broadcast from a transient, unsaved model so the passenger's live map
 * never stalls just because that particular fix wasn't worth a new row).
 *
 * Origin      : App\Services\GpsLocationService::record()
 * Destination : private WebSocket channel of the trip -> Reverb -> Laravel Echo (Flutter)
 *
 * The constructor extracts plain scalars from the model so the payload is
 * trivially serializable when the event is queued.
 */
class BusLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /** Null when this fix was broadcast but not persisted (see class docblock). */
    public ?int $locationId;

    public int $tripId;

    public int $busId;

    public float $latitude;

    public float $longitude;

    public ?float $speedKmh;

    /** ISO-8601 in UTC, e.g. "2026-09-03T15:00:00.000000Z". */
    public string $recordedAt;

    public function __construct(GpsLocation $location)
    {
        // bus_id is not a column on gps_locations; it comes from the trip.
        $location->loadMissing('trip');

        // Unsaved (transient) model: this fix was broadcast but not persisted.
        $this->locationId = $location->exists ? (int) $location->id : null;
        $this->tripId = (int) $location->trip_id;
        $this->busId = (int) $location->trip->bus_id;
        $this->latitude = (float) $location->latitude;
        $this->longitude = (float) $location->longitude;
        $this->speedKmh = $location->speed_kmh !== null ? (float) $location->speed_kmh : null;
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
            'recorded_at' => $this->recordedAt,
        ];
    }
}
