<?php

namespace App\Http\Resources;

use App\Http\Resources\JsonApi\JsonApiResource;
use App\Models\Trip;
use Illuminate\Http\Request;

/**
 * JSON:API resource for `trips`.
 *
 * type -> "trips". Returned by start / finish (driver) and as an included
 * resource of a GPS reading (`?include=trip`). Only exposes what the clients
 * need, not the whole row (no planned route snapshot, no cancellation data).
 *
 * @mixin Trip
 */
class TripResource extends JsonApiResource
{
    /**
     * Explicit JSON:API type, so the OpenAPI docs (Scramble) show the real value.
     */
    public function toType(Request $request): string
    {
        return 'trips';
    }

    public $attributes = [
        'status',
        'route_pattern_id',
        'bus_id',
        'driver_id',
        'service_date',
        'scheduled_departure_at',
        'scheduled_arrival_at',
        'actual_departure_at',
        'actual_arrival_at',
    ];

    public $relationships = [
        // 'route_pattern', 'bus', 'driver' -> add these when a user story needs them.
    ];
}
