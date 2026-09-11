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
     * Records a GPS reading (HU1) and returns the persisted model — or `null`
     * when the reading was accepted and broadcast live but NOT written to
     * `gps_locations` (below the distance/time threshold, see config/gps.php
     * and shouldPersist()). The caller (GpsLocationController) maps `null` to
     * a 202 with no `data`, still a success response.
     *
     * The PostGIS `location` column is derived by the model itself
     * (App\Models\Concerns\HasLocationPoint).
     */
    public function record(GpsFix $fix): ?GpsLocation
    {
        $last = $this->lastPersistedFor($fix->tripId);

        if ($this->shouldPersist($fix, $last)) {
            $location = GpsLocation::create($fix->toDatabaseRow());
            $this->broadcast($location);

            return $location;
        }

        // Below the threshold: HU2 (the passenger's live map) must not stall
        // just because this fix isn't worth a new row, so it's still
        // broadcast — from a transient (unsaved) model built straight from
        // the fix, reusing the trip already loaded on $last.
        $last->loadMissing('trip');
        $transient = (new GpsLocation($fix->toDatabaseRow()))->setRelation('trip', $last->trip);
        $this->broadcast($transient);

        return null;
    }

    /**
     * Whether $fix is worth a new `gps_locations` row: it's the trip's first
     * reading, or the bus moved at least `persist_min_distance_meters` since
     * $last, or at least `persist_min_interval_seconds` elapsed since $last
     * (whichever comes first) — so a stopped bus still leaves a periodic
     * "still here" trail instead of going silent in the historical data.
     */
    private function shouldPersist(GpsFix $fix, ?GpsLocation $last): bool
    {
        if ($last === null) {
            return true;
        }

        $secondsSinceLast = abs($last->recorded_at->diffInSeconds($fix->recordedAt));
        if ($secondsSinceLast >= (int) config('gps.persist_min_interval_seconds')) {
            return true;
        }

        $distanceMeters = $this->haversineMeters(
            $last->latitude,
            $last->longitude,
            $fix->latitude,
            $fix->longitude,
        );

        return $distanceMeters >= (float) config('gps.persist_min_distance_meters');
    }

    private function lastPersistedFor(int $tripId): ?GpsLocation
    {
        return GpsLocation::query()
            ->where('trip_id', $tripId)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Great-circle distance in meters between two coordinates (haversine).
     * no need for a PostGIS round-trip on every ingested reading.
     */
    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusMeters = 6_371_000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $earthRadiusMeters * asin(min(1.0, sqrt($a)));
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
