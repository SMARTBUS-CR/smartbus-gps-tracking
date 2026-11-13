<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    /*
    | Paths covered by CORS. Internally every route of this service lives under
    | /api/* (see bootstrap/app.php -> apiPrefix), which also covers
    | /api/broadcasting/auth.
    |
    | Note: Flutter is not a browser, so CORS does not apply to it. This is for a
    | possible web client (admin panel, web passenger). Reverb's WebSocket
    | handshake does not go through CORS either (it is controlled by
    | reverb.apps.*.allowed_origins).
    */
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // TODO: in production, restrict to the web client's domain(s).
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
