<?php

namespace App\Data;

use App\Models\Trip;
use Carbon\CarbonImmutable;

/**
 * What a passenger needs to show a trip on the map: the trip status, the bus's
 * current position (if any) and how fresh that position is.
 */
final readonly class TripTrackingState
{
    /** The position is recent: the bus is transmitting. */
    public const LIVE = 'live';

    /** The position is a few minutes old (weak signal, app in background...). */
    public const STALE = 'stale';

    /** No position yet, or too old to trust. */
    public const NO_SIGNAL = 'no_signal';

    /** The trip is not running (scheduled, completed or cancelled). */
    public const NOT_TRACKING = 'not_tracking';

    public function __construct(
        public Trip $trip,
        public ?TripPosition $position,
        public CarbonImmutable $now,
    ) {}

    public function ageSeconds(): ?int
    {
        if ($this->position === null) {
            return null;
        }

        return max(0, (int) $this->position->recordedAt->diffInSeconds($this->now, absolute: false));
    }

    public function freshness(): string
    {
        if (! $this->trip->isInProgress()) {
            return self::NOT_TRACKING;
        }

        $age = $this->ageSeconds();

        return match (true) {
            $age === null => self::NO_SIGNAL,
            $age <= (int) config('gps.position_live_seconds') => self::LIVE,
            $age <= (int) config('gps.position_stale_seconds') => self::STALE,
            default => self::NO_SIGNAL,
        };
    }

    /**
     * JSON:API document (type `trip-trackings`, id = trip id).
     *
     * @return array<string, mixed>
     */
    public function toJsonApi(): array
    {
        return [
            'data' => [
                'type' => 'trip-trackings',
                'id' => $this->trip->id,
                'attributes' => [
                    'trip_id' => $this->trip->id,
                    'status' => $this->trip->status,
                    'freshness' => $this->freshness(),
                    'age_seconds' => $this->ageSeconds(),
                    'position' => $this->position?->toArray(),
                ],
            ],
        ];
    }
}
