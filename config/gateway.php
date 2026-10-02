<?php

/*
|--------------------------------------------------------------------------
| API Gateway / Authentication contract
|--------------------------------------------------------------------------
| The GPS microservice does NOT own users or tokens. The API Gateway validates
| the client's  Authorization: Bearer <token>  against the Authentication
| Service and then forwards the SAME header to this service (it does not
| inject X-User-* headers).
|
| When a request needs to know WHO the user is (e.g. channel authorization on
| /api/broadcasting/auth), IdentifyFromGateway resolves the identity by asking
| the Authentication Service:  GET {auth.url}/api/user?include=roles
| with that Bearer token, and caches the answer per token.
|
| The identity is resolved lazily: routes that never call $request->user()
| (e.g. POST /api/locations) never hit the Authentication Service.
*/

return [

    'auth' => [
        // Same env name the API Gateway uses for the Authentication Service.
        'url' => env('AUTH_SERVICE_URL', 'http://localhost:8000'),

        // Returns the token's user as JSON:API, with its roles as a relationship.
        'user_path' => env('AUTH_SERVICE_USER_PATH', '/api/user?include=roles'),

        'timeout' => (int) env('AUTH_SERVICE_TIMEOUT', 5),

        // Seconds a resolved identity is cached per token (only successes are cached).
        'cache_ttl' => (int) env('AUTH_SERVICE_CACHE_TTL', 300),
    ],

];
