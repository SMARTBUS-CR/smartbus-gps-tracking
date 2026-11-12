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
|  1. Trip ends only (HU1, App\Services\GpsLocationService::record()) — a
|     reading is only WRITTEN to gps_locations when it is the trip's FIRST
|     reading (the trip started) or the app marks it `final` (sent right
|     before "Finalizar recorrido"). Every valid reading is still broadcast
|     live over WebSockets at once (HU2): the passenger's map gets the bus
|     every ~5 s. A reading that is not persisted still returns a successful
|     response (202 `broadcast_only`, see GpsLocationController::store()).
|
|  2. Retention (housekeeping, App\Console\Commands\PruneGpsLocations) — old
|     rows of trips that are already finished get deleted on a schedule.
|     Independent of #1: it only prunes what has already been persisted.
|
*/

return [

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

    /**
     * Freshness of the current position shown to passengers
     * (GET /api/trips/{tripId}/location, attribute `freshness`):
     *   live      -> taken at most `position_live_seconds` ago
     *   stale     -> taken at most `position_stale_seconds` ago
     *   no_signal -> older than that, or no position yet
     * The driver's app sends a reading every ~5 s.
     */
    'position_live_seconds' => (int) env('GPS_POSITION_LIVE_SECONDS', 30),
    'position_stale_seconds' => (int) env('GPS_POSITION_STALE_SECONDS', 300),

];
