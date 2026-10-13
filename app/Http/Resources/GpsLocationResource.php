<?php

namespace App\Http\Resources;

use App\Http\Resources\JsonApi\JsonApiResource;
use App\Models\GpsLocation;
use Illuminate\Http\Request;

/**
 * JSON:API resource for `gps_locations`.
 *
 * type -> "gps-locations" (the base class derives it as kebab-case plural)
 * id   -> gps_locations.id
 *
 * Response:
 *   {
 *     "data": {
 *       "type": "gps-locations",
 *       "id": "01a0a1f0-9c2e-7b3a-8f41-2d6e5c7b9a10",
 *       "attributes": {
 *         "trip_id": "01a09332-3457-7315-885e-4ebb218f7262",
 *         "latitude": 10.4631,
 *         "longitude": -83.9921,
 *         "speed_kmh": 38.5,
 *         "recorded_at": "2026-09-03T15:00:00.000000Z"
 *       }
 *     }
 *   }
 *
 * With `?include=trip` the full trip is embedded under `included`.
 *
 * @mixin GpsLocation
 */
class GpsLocationResource extends JsonApiResource
{
    /**
     * Explicit JSON:API type, so the OpenAPI docs (Scramble) show the real value.
     */
    public function toType(Request $request): string
    {
        return 'gps-locations';
    }

    /**
     * Listed by name: the native resource engine reads $this->resource->{name}.
     *
     * `trip_id` is exposed as an attribute (Flutter needs it for the marker) in
     * addition to the `trip` relationship below.
     */
    public $attributes = [
        'trip_id',
        'latitude',
        'longitude',
        'speed_kmh',
        'recorded_at',
    ];

    /**
     * Optional relationship: only materialized with `?include=trip`, and
     * serialized with TripResource rather than the generic fallback.
     */
    public $relationships = [
        'trip' => TripResource::class,
    ];
}
