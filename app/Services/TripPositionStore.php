<?php

namespace App\Services;

use App\Data\TripPosition;
use App\Models\GpsLocation;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the current position of each trip.
 *
 * The cache holds the latest ACCEPTED reading (persisted or not), so the
 * passenger's map gets the freshest point. If the cache is lost (restart on
 * Render, cleared cache), it falls back to the latest row of `gps_locations`.
 * No extra table is needed.
 */
class TripPositionStore
{
    /** Positions of a running trip are refreshed every few seconds. */
    private const TTL_SECONDS = 86_400;

    public function get(string $tripId): ?TripPosition
    {
        $cached = Cache::get($this->key($tripId));
        if (is_array($cached)) {
            return TripPosition::fromArray($cached);
        }

        $latest = GpsLocation::query()
            ->where('trip_id', $tripId)
            ->orderByDesc('recorded_at')
            ->first();

        return $latest ? TripPosition::fromLocation($latest) : null;
    }

    public function put(string $tripId, TripPosition $position): void
    {
        Cache::put($this->key($tripId), $position->toArray(), self::TTL_SECONDS);
    }

    private function key(string $tripId): string
    {
        return "gps:trip:{$tripId}:position";
    }
}
