<?php

namespace App\Http\Requests;

use App\Data\GpsFix;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida el cuerpo JSON:API de  POST /gps/locations.
 *
 * Forma esperada:
 *   {
 *     "data": {
 *       "type": "gps-locations",
 *       "attributes": {
 *         "trip_id": 25,
 *         "latitude": 10.4631,
 *         "longitude": -83.9921,
 *         "speed_kmh": 38.5,
 *         "recorded_at": "2026-09-03T15:00:00Z"
 *       }
 *     }
 *   }
 *
 * Solo valida FORMA y coherencia basica. Las reglas de negocio (viaje activo,
 * lectura no demasiado antigua, orden temporal...) viven en GpsLocationService.
 */
class StoreGpsLocationRequest extends FormRequest
{
    /**
     * La autenticacion/autorizacion la resuelve el API Gateway.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'data' => ['required', 'array'],
            'data.type' => ['required', 'string', Rule::in(['gps-locations'])],
            'data.attributes' => ['required', 'array'],

            'data.attributes.trip_id' => ['required', 'integer', 'exists:trips,id'],
            'data.attributes.latitude' => ['required', 'numeric', 'between:-90,90'],
            'data.attributes.longitude' => ['required', 'numeric', 'between:-180,180'],
            'data.attributes.speed_kmh' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:400'],
            'data.attributes.recorded_at' => ['required', 'date'],
        ];
    }

    /**
     * Nombres legibles para los mensajes de error (":attribute").
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'data' => 'document',
            'data.type' => 'type',
            'data.attributes' => 'attributes',
            'data.attributes.trip_id' => 'trip_id',
            'data.attributes.latitude' => 'latitude',
            'data.attributes.longitude' => 'longitude',
            'data.attributes.speed_kmh' => 'speed_kmh',
            'data.attributes.recorded_at' => 'recorded_at',
        ];
    }

    /**
     * DTO inmutable con la lectura ya validada.
     */
    public function toGpsFix(): GpsFix
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $this->validated('data.attributes');

        return GpsFix::fromAttributes($attributes);
    }
}
