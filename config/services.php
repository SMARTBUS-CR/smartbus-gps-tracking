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
    | OpenRouteService - ruta / distancia / matriz. Integracion FUTURA.
    | Se consumira detras de una capa de servicio, nunca desde un controller.
    | Ver docs/INTEGRATIONS.md.
    */
    'openrouteservice' => [
        'base_url' => env('ORS_BASE_URL', 'https://api.openrouteservice.org'),
        'api_key' => env('ORS_API_KEY'),
        'profile' => env('ORS_PROFILE', 'driving-hgv'), // bus ~ heavy goods vehicle
        'timeout' => (int) env('ORS_TIMEOUT', 5),
    ],

    /*
    | OpenStreetMap - tiles del mapa. Lo consume FLUTTER directamente.
    | El backend solo guarda la URL por si se centraliza el proveedor.
    | Ver docs/INTEGRATIONS.md.
    */
    'openstreetmap' => [
        'tile_url' => env('OSM_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'attribution' => env('OSM_ATTRIBUTION', '(c) OpenStreetMap contributors'),
    ],

];
