<?php

namespace App\Http\Resources;

use App\Http\Resources\JsonApi\JsonApiResource;

/**
 * JSON:API resource for `trips`.
 *
 * type -> "trips". Used mostly as an included resource
 * (GET /api/gps/locations?include=trip) for the passenger map. Only exposes
 * what the client needs, not the whole row.
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
        // 'route', 'bus', 'driver' -> add these when a user story needs them.
    ];
}
