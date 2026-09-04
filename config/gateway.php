<?php

/*
|--------------------------------------------------------------------------
| Contrato con el API Gateway
|--------------------------------------------------------------------------
| El microservicio GPS NO autentica: confia en la identidad que el API
| Gateway ya verifico y reenvia en cabeceras HTTP.
|
| El Gateway DEBE:
|   - validar el token del usuario,
|   - reenviar estas cabeceras en TODA request hacia /gps/* (incluida
|     /gps/broadcasting/auth),
|   - NO permitir que el cliente las falsifique (stripear las entrantes).
|
| La red entre Gateway y este servicio se asume de confianza (no expuesta).
*/

return [

    'headers' => [
        'user_id' => env('GATEWAY_HEADER_USER_ID', 'X-Auth-User-Id'),
        'roles' => env('GATEWAY_HEADER_ROLES', 'X-Auth-Roles'),
        'company_id' => env('GATEWAY_HEADER_COMPANY_ID', 'X-Auth-Company-Id'),
    ],

];
