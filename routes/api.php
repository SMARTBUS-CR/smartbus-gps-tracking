<?php

use App\Http\Controllers\Api\GpsLocationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - SmartBus GPS Microservice
|--------------------------------------------------------------------------
| URL prefix: /api  (bootstrap/app.php -> apiPrefix).
| Via the API Gateway: /api/gps/{path}  (the Gateway prepends the `gps` segment).
| Middleware group: "api" + IdentifyFromGateway + NegotiatesJsonApi.
| Authentication is handled by the API Gateway, NOT this microservice.
| Every response (errors included) follows the JSON:API standard.
*/

Route::get('/ping', fn () => response()->json([
    'service' => 'smartbus-gps',
    'status' => 'ok',
]))->name('gps.ping');

/*
| HU1 - Receive GPS coordinates.
| JSON:API body: { "data": { "type": "gps-locations", "attributes": { ... } } }
*/
Route::post('/locations', [GpsLocationController::class, 'store'])
    ->name('gps.locations.store');

/*
| HU3 - Latest known position of a trip (initial state of the passenger map,
| before the next BusLocationUpdated event arrives).
*/
Route::get('/trips/{tripId}/location', [GpsLocationController::class, 'latestForTrip'])
    ->name('gps.trips.location');
