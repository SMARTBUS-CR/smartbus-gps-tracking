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
    | Rutas cubiertas por CORS:
    |  - gps/*            -> API del microservicio
    |  - broadcasting/auth -> autorizacion de canales privados de Reverb
    |
    | Nota: Flutter NO es un navegador, no le aplica CORS. Esto es para un
    | eventual cliente web (panel, pasajero web). El handshake WebSocket de
    | Reverb tampoco pasa por CORS (lo controla reverb.apps.*.allowed_origins).
    */
    'paths' => ['gps/*', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    // TODO: en produccion restringir al/los dominio(s) del cliente web.
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
