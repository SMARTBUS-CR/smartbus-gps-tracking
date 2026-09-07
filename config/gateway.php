<?php

/*
|--------------------------------------------------------------------------
| Contrato con el API Gateway
|--------------------------------------------------------------------------
| El microservicio GPS NO autentica: confia en la identidad que el API
| Gateway (Laravel, en Render) ya verifico y reenvia en cabeceras HTTP.
|
| El Gateway ya usa este mismo patron con el Authentication Service:
|   valida  Authorization: Bearer <token>  y reenvia  X-User-Id / X-User-Roles.
|
| El Gateway DEBE, para toda request a  /api/gps/*  (incl. /api/gps/broadcasting/auth):
|   - validar el token,
|   - reenviar X-User-Id (y X-User-Roles),
|   - eliminar cualquier X-User-* que venga del cliente (anti-spoofing).
|
| La red entre Gateway y este servicio se asume de confianza (no expuesta).
*/

return [

    'headers' => [
        // Nombres que YA usa el Gateway de SmartBus.
        'user_id' => env('GATEWAY_HEADER_USER_ID', 'X-User-Id'),
        'roles' => env('GATEWAY_HEADER_ROLES', 'X-User-Roles'),
        // El Gateway aun NO envia company_id; queda configurable por si se agrega.
        'company_id' => env('GATEWAY_HEADER_COMPANY_ID', 'X-User-Company-Id'),
    ],

];
