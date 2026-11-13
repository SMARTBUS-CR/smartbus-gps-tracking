<?php

namespace App\Data;

use App\Models\GpsLocation;

/**
 * Outcome of recording one GPS reading (App\Services\GpsLocationService).
 */
final readonly class RecordResult
{
    /** Broadcast live and stored as a historical sample. */
    public const PERSISTED = 'persisted';

    /** Broadcast live, not stored (neither the start nor the end of the trip). */
    public const BROADCAST_ONLY = 'broadcast_only';

    /** Same `recorded_at` as the current position: already processed. */
    public const DUPLICATE = 'duplicate';

    /** Taken before the current position (arrived late): it must not move the bus back. */
    public const STALE = 'stale';

    private function __construct(
        public string $outcome,
        public ?GpsLocation $location = null,
    ) {}

    public static function persisted(GpsLocation $location): self
    {
        return new self(self::PERSISTED, $location);
    }

    public static function broadcastOnly(): self
    {
        return new self(self::BROADCAST_ONLY);
    }

    public static function duplicate(): self
    {
        return new self(self::DUPLICATE);
    }

    public static function stale(): self
    {
        return new self(self::STALE);
    }

    /** Duplicate or late readings are acknowledged but ignored. */
    public function wasIgnored(): bool
    {
        return in_array($this->outcome, [self::DUPLICATE, self::STALE], true);
    }
}
