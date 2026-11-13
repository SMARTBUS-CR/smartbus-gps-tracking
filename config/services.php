<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Arrival estimates (ETA), calculated by `php artisan gps:eta-watch` in its
    | own process (never inside the driver's GPS request). OFF while
    | ETA_SERVICE_URL or DASHBOARD_URL is empty. Both services receive the
    | driver's own token. See docs/EVENTS.md (TripArrivalsUpdated).
    */
    'eta' => [
        // e.g. https://smartbus-eta.onrender.com/api/v1/eta
        'url' => env('ETA_SERVICE_URL'),
        'timeout' => (float) env('ETA_TIMEOUT_SECONDS', 8),
        // Seconds between passes over the trips in progress.
        'interval' => (float) env('ETA_REFRESH_SECONDS', 5),
        // Older positions are skipped (the ETA service calls them stale after 30 s).
        'max_position_age' => (int) env('ETA_MAX_POSITION_AGE_SECONDS', 30),
        // Farther than this from the route line = off route.
        'on_route_meters' => (float) env('ETA_ON_ROUTE_METERS', 100),
    ],

    /*
    | Dashboard (operations): ETA context of a trip
    | (GET /api/internal/v1/trips/{id}/tracking-context?projection=eta-v1).
    */
    'dashboard' => [
        // e.g. https://smartbus-dashboard-dev.onrender.com
        'url' => env('DASHBOARD_URL'),
        'timeout' => (float) env('DASHBOARD_TIMEOUT', 8),
    ],

    /*
    | OpenRouteService - ruta / distancia / matriz. Integracion FUTURA.
    | It will be consumed behind a service layer, never from a controller.
    | Ver docs/INTEGRATIONS.md.
    */
    'openrouteservice' => [
        'base_url' => env('ORS_BASE_URL', 'https://api.openrouteservice.org'),
        'api_key' => env('ORS_API_KEY'),
        'profile' => env('ORS_PROFILE', 'driving-hgv'), // bus ~ heavy goods vehicle
        'timeout' => (int) env('ORS_TIMEOUT', 5),
    ],

    /*
    | OpenStreetMap - map tiles. NOT used by the app: Flutter renders the map with
    | MapLibre + OpenFreeMap styles (configured in the Flutter app).
    | Kept in case the backend ever has to tell the app which map provider to use.
    | See docs/INTEGRATIONS.md.
    */
    'openstreetmap' => [
        'tile_url' => env('OSM_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'attribution' => env('OSM_ATTRIBUTION', '(c) OpenStreetMap contributors'),
    ],

];
