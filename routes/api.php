<?php

use App\Http\Controllers\Api\GpsLocationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - SmartBus GPS Microservice
|--------------------------------------------------------------------------
| Prefijo de URL: /api  (config en bootstrap/app.php -> apiPrefix).
| Via API Gateway: /api/gps/{path}  (el Gateway antepone el segmento gps).
| Grupo de middleware: "api" + IdentifyFromGateway + NegotiatesJsonApi.
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

/*
| HU3 - Ultima posicion conocida de un viaje (estado inicial del mapa del pasajero,
| antes de que llegue el siguiente evento BusLocationUpdated).
*/
Route::get('/trips/{tripId}/location', [GpsLocationController::class, 'latestForTrip'])
    ->name('gps.trips.location');
