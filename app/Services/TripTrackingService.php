<?php

namespace App\Services;

use App\Events\TripTrackingEnded;
use App\Models\Trip;
use App\Support\Gateway\GatewayUser;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Start / finish of a trip by its assigned driver.
 *
 * The `trips` table belongs to the Dashboard; GPS only moves the execution
 * state the database allows (trips_execution_check):
 *   scheduled   --start-->  in_progress (actual_departure_at = now)
 *   in_progress --finish--> completed   (actual_arrival_at = now)
 */
class TripTrackingService
{
    public function __construct(
        private readonly TripDriverGuard $guard,
        private readonly TripPositionStore $positions,
    ) {}

    public function start(Trip $trip, GatewayUser $driver): Trip
    {
        return DB::transaction(function () use ($trip, $driver) {
            $trip = $this->lockedForUpdate($trip);
            $this->guard->authorize($trip, $driver);

            if ($trip->status !== Trip::STATUS_SCHEDULED) {
                throw new ConflictHttpException("The trip cannot be started: it is {$trip->status}.");
            }

            $trip->update([
                'status' => Trip::STATUS_IN_PROGRESS,
                'actual_departure_at' => now(),
            ]);

            return $trip;
        });
    }

    public function finish(Trip $trip, GatewayUser $driver): Trip
    {
        $trip = DB::transaction(function () use ($trip, $driver) {
            $trip = $this->lockedForUpdate($trip);
            $this->guard->authorize($trip, $driver);

            if (! $trip->isInProgress()) {
                throw new ConflictHttpException("The trip cannot be finished: it is {$trip->status}.");
            }

            $trip->update([
                'status' => Trip::STATUS_COMPLETED,
                'actual_arrival_at' => now(),
            ]);

            return $trip;
        });

        $this->positions->forget($trip->id);

        // Best-effort: the trip is already finished even if Reverb is down.
        try {
            TripTrackingEnded::dispatch($trip);
        } catch (Throwable $e) {
            report($e);
        }

        return $trip;
    }

    private function lockedForUpdate(Trip $trip): Trip
    {
        return Trip::query()->lockForUpdate()->findOrFail($trip->getKey());
    }
}
