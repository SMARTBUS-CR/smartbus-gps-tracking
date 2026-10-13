<?php

/*
|--------------------------------------------------------------------------
| API Gateway contract
|--------------------------------------------------------------------------
| The GPS microservice does NOT own users or tokens. The API Gateway validates
| the client's  Authorization: Bearer <token>  and forwards the SAME header to
| this service (it does not inject X-User-* headers).
|
| To know WHO the user is, IdentifyFromGateway asks the API Gateway itself
| (never the Authentication Service directly, so every auth check goes through
| the Gateway):
|
|     GET {identity.url}/api/auth/user?include=roles     Authorization: Bearer <token>
|
| The Gateway validates the token (validate.token) and proxies the request to
| the Authentication Service. The answer is cached per token.
|
| Every API route except /api/ping requires a user (RequireGatewayUser), so a
| request without a valid token gets a 401 even if it reaches this service
| without going through the Gateway.
*/

return [

    'identity' => [
        // Base URL of the API Gateway (Render), e.g. https://smartbus-api-gateway-dev.onrender.com
        'url' => env('GATEWAY_URL', 'http://localhost:8001'),

        // Gateway route that returns the token's user as JSON:API, with its roles.
        'user_path' => env('GATEWAY_USER_PATH', '/api/auth/user?include=roles'),

        'timeout' => (int) env('GATEWAY_TIMEOUT', 5),

        // Seconds a resolved identity is cached per token (only successes are cached).
        'cache_ttl' => (int) env('GATEWAY_IDENTITY_CACHE_TTL', 300),
    ],

];
