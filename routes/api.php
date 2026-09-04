<?php

use App\Http\Controllers\Api\GpsLocationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - SmartBus GPS Microservice
|--------------------------------------------------------------------------
| Prefijo de URL: /gps  (config en bootstrap/app.php -> apiPrefix).
| Grupo de middleware: "api" + NegotiatesJsonApi (content negotiation JSON:API).
| La autenticacion la resuelve el API Gateway, NO este microservicio.
| Todas las respuestas (incluidos errores) siguen el estandar JSON:API.
*/

Route::get('/ping', fn () => response()->json([
    'service' => 'smartbus-gps',
    'status' => 'ok',
]))->name('gps.ping');

/*
| HU1 - Recibir coordenadas GPS.
| Body JSON:API: { "data": { "type": "gps-locations", "attributes": { ... } } }
*/
Route::post('/locations', [GpsLocationController::class, 'store'])
    ->name('gps.locations.store');
