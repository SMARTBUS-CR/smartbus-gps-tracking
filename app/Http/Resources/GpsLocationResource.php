<?php

namespace App\Http\Resources;

use App\Http\Resources\JsonApi\JsonApiResource;

/**
 * Recurso JSON:API para `gps_locations`.
 *
 * type  -> "gps-locations"  (lo deriva la clase base en kebab-case plural)
 * id    -> gps_locations.id
 *
 * Respuesta:
 *   {
 *     "data": {
 *       "type": "gps-locations",
 *       "id": "1",
 *       "attributes": {
 *         "trip_id": 25,
 *         "latitude": 10.4631,
 *         "longitude": -83.9921,
 *         "speed_kmh": 38.5,
 *         "recorded_at": "2026-09-03T15:00:00.000000Z"
 *       }
 *     }
 *   }
 *
 * Con  ?include=trip  se agrega el viaje completo en `included`.
 */
class GpsLocationResource extends JsonApiResource
{
    /**
     * Se listan por nombre: el motor nativo lee $this->resource->{campo}.
     *
     * `trip_id` se expone como atributo (lo necesita Flutter para el marcador,
     * ver Step 11) ademas de la relacion `trip` de abajo.
     */
    public $attributes = [
        'trip_id',
        'latitude',
        'longitude',
        'speed_kmh',
        'recorded_at',
    ];

    /**
     * Relacion opcional: solo se materializa con ?include=trip,
     * y se serializa con TripResource (no con el fallback generico).
     */
    public $relationships = [
        'trip' => TripResource::class,
    ];
}
