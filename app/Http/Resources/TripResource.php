<?php

namespace App\Http\Resources;

use App\Http\Resources\JsonApi\JsonApiResource;

/**
 * Recurso JSON:API para `trips`.
 *
 * type -> "trips". Se usa sobre todo como recurso incluido
 * (GET /gps/locations?include=trip) para el mapa del pasajero.
 * Solo expone lo que el cliente necesita, no toda la fila.
 */
class TripResource extends JsonApiResource
{
    public $attributes = [
        'route_id',
        'bus_id',
        'driver_id',
        'status',
        'started_at',
    ];

    public $relationships = [
        // 'route', 'bus', 'driver'  -> se abren cuando alguna HU los pida
    ];
}
