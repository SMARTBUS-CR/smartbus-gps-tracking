<?php

namespace App\Http\Requests;

use App\Data\GpsFix;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the JSON:API request body of POST /api/gps/locations.
 *
 * Expected shape:
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
 * Only shape and basic sanity are checked here. Business rules (trip active,
 * reading not too old, chronological order, ...) live in GpsLocationService.
 */
class StoreGpsLocationRequest extends FormRequest
{
    /**
     * Authentication / authorization is handled by the API Gateway.
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
     * Readable names for the ":attribute" placeholder in error messages.
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
     * The validated reading as an immutable DTO.
     */
    public function toGpsFix(): GpsFix
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $this->validated('data.attributes');

        return GpsFix::fromAttributes($attributes);
    }
}
