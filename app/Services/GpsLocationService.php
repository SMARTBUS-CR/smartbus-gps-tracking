<?php

namespace App\Services;

use App\Data\GpsFix;
use App\Events\BusLocationUpdated;
use App\Models\GpsLocation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

/**
 * Application logic for GPS readings.
 *
 * The single place where a `gps_locations` row is born. Future business rules
 * hook in here (trip must be active, discard stale / out-of-order readings, feed
 * the ETA microservice). Controllers never touch the model directly — they go
 * through this service.
 */
class GpsLocationService
{
    /**
     * Stores a GPS reading and returns the persisted model (HU1).
     *
     * The PostGIS `location` column is derived by the model itself
     * (App\Models\Concerns\HasLocationPoint).
     */
    public function record(GpsFix $fix): GpsLocation
    {
        $location = GpsLocation::create($fix->toDatabaseRow());

        $this->broadcast($location);

        return $location;
    }

    /**
     * Latest GPS reading of a trip (HU3 — initial state of the passenger map).
     *
     * @throws ModelNotFoundException when the trip
     *                                does not exist or has no readings yet; the controller maps it to 404.
     */
    public function latestForTrip(int $tripId): GpsLocation
    {
        return GpsLocation::query()
            ->where('trip_id', $tripId)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * Broadcasts the new coordinate over WebSockets (Reverb -> Echo) for HU2.
     *
     * Best-effort: if Reverb is down or the broadcast fails, the reading is
     * already stored and ingestion (HU1) must not break. In production, with
     * QUEUE_CONNECTION=redis, the queued job also retries on its own.
     */
    private function broadcast(GpsLocation $location): void
    {
        try {
            BusLocationUpdated::dispatch($location);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
