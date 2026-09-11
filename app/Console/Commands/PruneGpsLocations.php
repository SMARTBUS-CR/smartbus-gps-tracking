<?php

namespace App\Console\Commands;

use App\Models\GpsLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Housekeeping for the GPS volume problem (see config/gps.php): deletes
 * `gps_locations` rows older than the configured retention window, but ONLY
 * for trips that are already finished (`completed_at` set).
 *
 * Deliberately does NOT touch ingestion (App\Services\GpsLocationService):
 * HU1's contract is that every valid coordinate received is stored — see
 * tests/Feature/Api/StoreGpsLocationTest.php. This command only prunes what
 * has already been persisted, after the fact, so it can be tuned (or turned
 * off) without changing that contract.
 *
 * A trip in progress is never pruned, no matter how old its first readings
 * are — only `completed_at IS NOT NULL` rows are eligible.
 */
class PruneGpsLocations extends Command
{
    protected $signature = 'gps:prune-locations
        {--days= : Overrides GPS_LOCATIONS_RETENTION_DAYS for this run.}
        {--chunk= : Overrides GPS_LOCATIONS_PRUNE_CHUNK for this run.}
        {--dry-run : Count what would be deleted without deleting anything.}';

    protected $description = 'Delete gps_locations rows of finished trips older than the retention window.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('gps.locations_retention_days'));
        $chunk = (int) ($this->option('chunk') ?? config('gps.locations_prune_chunk'));
        $cutoff = Carbon::now()->subDays($days);

        $eligible = GpsLocation::query()
            ->whereHas('trip', fn ($q) => $q->whereNotNull('completed_at'))
            ->where('recorded_at', '<', $cutoff);

        $total = (clone $eligible)->count();

        if ($total === 0) {
            $this->info("No gps_locations rows older than {$days} days on finished trips.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$total} row(s) would be deleted (recorded_at < {$cutoff->toDateTimeString()}).");

            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            // Re-run the query each iteration instead of paginating: rows already
            // deleted shift the offset, so LIMIT-only deletes are simpler and safe.
            $ids = (clone $eligible)->limit($chunk)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            GpsLocation::query()->whereIn('id', $ids)->delete();
            $deleted += $ids->count();
        } while ($ids->count() === $chunk);

        $this->info("Deleted {$deleted} gps_locations row(s) older than {$days} days on finished trips.");

        return self::SUCCESS;
    }
}
