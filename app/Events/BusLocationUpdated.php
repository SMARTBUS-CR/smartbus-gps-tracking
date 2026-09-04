<?php

namespace App\Events;

use App\Models\GpsLocation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara cada vez que se registra una nueva coordenada GPS de un viaje (HU2).
 *
 * Origen  : App\Services\GpsLocationService::record()
 * Destino : canal WebSocket del viaje  ->  Reverb  ->  Laravel Echo (Flutter)
 *
 * El payload (broadcastWith) se afina para Flutter en el Step 11.
 * El canal (broadcastOn) y su visibilidad publico/privado se definen en el Step 10.
 */
class BusLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public int $locationId;

    public int $tripId;

    public int $busId;

    public float $latitude;

    public float $longitude;

    public ?float $speedKmh;

    /** ISO-8601 UTC, p.ej. "2026-09-03T15:00:00.000000Z" */
    public string $recordedAt;

    public function __construct(GpsLocation $location)
    {
        // bus_id no esta en gps_locations: viene del viaje.
        $location->loadMissing('trip');

        $this->locationId = (int) $location->id;
        $this->tripId = (int) $location->trip_id;
        $this->busId = (int) $location->trip->bus_id;
        $this->latitude = (float) $location->latitude;
        $this->longitude = (float) $location->longitude;
        $this->speedKmh = $location->speed_kmh !== null ? (float) $location->speed_kmh : null;
        $this->recordedAt = $location->recorded_at->toISOString();
    }

    /**
     * Canal PRIVADO del viaje. La suscripcion se autoriza en routes/channels.php
     * via POST /gps/broadcasting/auth.
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
     * Nombre con el que el cliente escucha:  .listen('.BusLocationUpdated', ...)
     */
    public function broadcastAs(): string
    {
        return 'BusLocationUpdated';
    }

    /**
     * Datos que recibe Flutter para mover el marcador. (Se afina en Step 11.)
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
