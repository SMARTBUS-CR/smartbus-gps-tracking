<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Trip;
use App\Support\Gateway\GatewayUser;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Only the driver assigned to a trip may act on it (start, finish, send GPS).
 *
 * Starts from the trip, never from the user: a user can be a driver in several
 * companies, but only `trips.driver_id` says who drives THIS trip. The driver
 * profile must also be active (not deactivated nor deleted by the Dashboard).
 */
class TripDriverGuard
{
    /**
     * @throws AuthorizationException Rendered as a JSON:API 403.
     */
    public function authorize(Trip $trip, GatewayUser $user): void
    {
        $isAssignedDriver = Driver::query()
            ->whereKey($trip->driver_id)
            ->where('user_id', $user->id)
            ->where('status', Driver::STATUS_ACTIVE)
            ->exists();

        if (! $isAssignedDriver) {
            throw new AuthorizationException('Only the driver assigned to this trip can do this.');
        }
    }
}
