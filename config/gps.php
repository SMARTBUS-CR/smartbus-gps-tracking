<?php

/*
|--------------------------------------------------------------------------
| GPS ingestion volume: transmit != persist
|--------------------------------------------------------------------------
| A bus reporting a fix every few seconds, 24/7, forever, does not need to
| turn into that many rows in `gps_locations` — most of them are redundant
| (a stopped bus reports the same point over and over) and the table would
| grow unboundedly. Two independent controls handle this:
|
|  1. Persist filter (HU1, App\Services\GpsLocationService::record()) — a
|     reading is only WRITTEN to gps_locations when it's the trip's first
|     reading, or it moved at least `persist_min_distance_meters`, or at
|     least `persist_min_interval_seconds` passed since the last one that
|     WAS persisted. Every valid reading is still broadcast live over
|     WebSockets regardless of this filter (HU2) — only the table is
|     affected, never the passenger's live map. A reading that is not
|     persisted still returns a successful response (202, no `data`, see
|     GpsLocationController::store()) — it was received and broadcast, it
|     just was not worth a new row.
|
|  2. Retention (housekeeping, App\Console\Commands\PruneGpsLocations) — old
|     rows of trips that are already finished get deleted on a schedule.
|     Independent of #1: it only prunes what has already been persisted.
| 
*/

return [

    /**
     * Minimum displacement (meters, great-circle) from the last PERSISTED
     * reading of the trip before a new one is written, unless the time
     * threshold below is met first.
     */
    'persist_min_distance_meters' => (float) env('GPS_PERSIST_MIN_DISTANCE_METERS', 15),

    /**
     * Maximum time (seconds) between persisted readings of a trip, even if
     * the bus has not moved `persist_min_distance_meters` — so a stopped bus
     * still leaves a periodic "still here" trail instead of going silent in
     * the historical data.
     */
    'persist_min_interval_seconds' => (int) env('GPS_PERSIST_MIN_INTERVAL_SECONDS', 60),

    /**
     * How many days a GPS reading is kept after the trip it belongs to has
     * completed. Readings of trips still in progress are never touched,
     * regardless of age.
     */
    'locations_retention_days' => (int) env('GPS_LOCATIONS_RETENTION_DAYS', 60),

    /**
     * Rows deleted per batch, so pruning a large backlog does not hold a
     * single long-running transaction / table lock.
     */
    'locations_prune_chunk' => (int) env('GPS_LOCATIONS_PRUNE_CHUNK', 5000),

];
