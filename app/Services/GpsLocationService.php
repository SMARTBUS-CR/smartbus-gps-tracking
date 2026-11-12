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
 *
 * Transmit != persist: every accepted reading is broadcast at once (the
 * passenger's map), but `gps_locations` only keeps the START (first reading
 * of the trip) and the END (reading sent with `final: true`) of each trip.
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

        // Only the ends of the trip are stored: its first reading (the bus
        // just started) and the one the app marks as final (right before
        // "Finalizar recorrido"). $current === null means the trip has no
        // position yet, neither cached nor stored.
        $location = null;
        if ($current === null || $fix->isFinal) {
            try {
                $location = GpsLocation::create($fix->toDatabaseRow());
            } catch (UniqueConstraintViolationException) {
                // Same (trip_id, recorded_at) already stored (e.g. the cache was lost).
                return RecordResult::duplicate();
            }
        }

        $this->positions->put($trip->id, TripPosition::fromFix($fix));

        // Every accepted reading reaches the passenger's live map right away,
        // stored or not.
        $broadcastable = $location
            ?? (new GpsLocation($fix->toDatabaseRow()))->setRelation('trip', $trip);
        $this->broadcast($broadcastable);

        return $location ? RecordResult::persisted($location) : RecordResult::broadcastOnly();
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
