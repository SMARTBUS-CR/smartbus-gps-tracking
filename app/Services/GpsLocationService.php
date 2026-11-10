<?php

namespace App\Services;

use App\Data\GpsFix;
use App\Data\RecordResult;
use App\Data\TripPosition;
use App\Events\BusLocationUpdated;
use App\Models\GpsLocation;
use App\Models\Trip;
use App\Support\Gateway\GatewayUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Application logic for GPS readings: the single place where a reading is
 * accepted, stored and broadcast. Controllers never touch the models directly.
 *
 * Every check happens BEFORE anything is stored or broadcast:
 *   1. the sender is the driver assigned to the trip      (403 otherwise)
 *   2. the trip is in progress                            (409 otherwise)
 *   3. the reading is newer than the current position     (ignored otherwise)
 */
class GpsLocationService
{
    public function __construct(
        private readonly TripDriverGuard $guard,
        private readonly TripPositionStore $positions,
    ) {}

    /**
     * Records one reading of the driver's app.
     *
     * Readings of the same trip are processed one at a time (cache lock), so
     * two requests arriving together can never move the bus back.
     */
    public function record(GpsFix $fix, GatewayUser $driver): RecordResult
    {
        $trip = Trip::query()->findOrFail($fix->tripId);

        $this->guard->authorize($trip, $driver);

        if (! $trip->isInProgress()) {
            throw new ConflictHttpException("The trip is not in progress: it is {$trip->status}.");
        }

        return Cache::lock("gps:trip:{$trip->id}:record", 10)
            ->block(5, fn () => $this->recordInOrder($fix, $trip));
    }

    private function recordInOrder(GpsFix $fix, Trip $trip): RecordResult
    {
        // A reading taken before the current position arrived late (e.g. after
        // a disconnection): it must not replace a newer one.
        $current = $this->positions->get($trip->id);
        if ($current !== null) {
            if ($fix->recordedAt->equalTo($current->recordedAt)) {
                return RecordResult::duplicate();
            }
            if ($fix->recordedAt->lessThan($current->recordedAt)) {
                return RecordResult::stale();
            }
        }

        $location = null;
        if ($this->shouldPersist($fix, $this->lastPersistedFor($trip->id))) {
            try {
                $location = GpsLocation::create($fix->toDatabaseRow());
            } catch (UniqueConstraintViolationException) {
                // Same (trip_id, recorded_at) already stored (e.g. the cache was lost).
                return RecordResult::duplicate();
            }
        }

        $this->positions->put($trip->id, TripPosition::fromFix($fix));

        // The passenger's live map must not stall just because this reading
        // isn't worth a new row: it is always broadcast.
        $broadcastable = $location
            ?? (new GpsLocation($fix->toDatabaseRow()))->setRelation('trip', $trip);
        $this->broadcast($broadcastable);

        return $location ? RecordResult::persisted($location) : RecordResult::broadcastOnly();
    }

    /**
     * Whether $fix is worth a new `gps_locations` row: it's the trip's first
     * stored reading, or the bus moved at least `persist_min_distance_meters`
     * since $last, or at least `persist_min_interval_seconds` elapsed since
     * $last — so a stopped bus still leaves a periodic "still here" trail.
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

    private function lastPersistedFor(string $tripId): ?GpsLocation
    {
        return GpsLocation::query()
            ->where('trip_id', $tripId)
            ->orderByDesc('recorded_at')
            ->first();
    }

    /**
     * Great-circle distance in meters between two coordinates (haversine):
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
     * Broadcasts the position over WebSockets (Reverb -> Echo).
     *
     * Best-effort: if Reverb is down, the reading is already accepted and
     * ingestion must not break.
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
