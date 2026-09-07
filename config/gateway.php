<?php

/*
|--------------------------------------------------------------------------
| API Gateway contract
|--------------------------------------------------------------------------
| The GPS microservice does NOT authenticate: it trusts the identity the API
| Gateway (Laravel, on Render) has already verified and forwards as HTTP headers.
|
| The Gateway already uses this same pattern with the Authentication Service:
|   it validates  Authorization: Bearer <token>  and forwards  X-User-Id / X-User-Roles.
|
| For every request to  /api/gps/*  (including /api/gps/broadcasting/auth) the
| Gateway MUST:
|   - validate the token,
|   - forward X-User-Id (and X-User-Roles),
|   - strip any X-User-* coming from the client (anti-spoofing).
|
| The network between the Gateway and this service is assumed trusted (not exposed).
*/

return [

    'headers' => [
        // Header names the SmartBus Gateway already sends.
        'user_id' => env('GATEWAY_HEADER_USER_ID', 'X-User-Id'),
        'roles' => env('GATEWAY_HEADER_ROLES', 'X-User-Roles'),
        // The Gateway does NOT send company_id yet; kept configurable in case it does.
        'company_id' => env('GATEWAY_HEADER_COMPANY_ID', 'X-User-Company-Id'),
    ],

];
