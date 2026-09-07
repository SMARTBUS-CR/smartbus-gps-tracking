<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * A validated GPS reading, ready to be persisted and transmitted.
 *
 * Immutable value object that decouples the transport (HTTP / JSON:API) from the
 * business logic: the controller builds a GpsFix from the request, and the
 * service only ever sees this type, never the request shape.
 */
final readonly class GpsFix
{
    public function __construct(
        public int $tripId,
        public float $latitude,
        public float $longitude,
        public ?float $speedKmh,
        public CarbonImmutable $recordedAt,
    ) {}

    /**
     * @param array{
     *     trip_id: int|string,
     *     latitude: int|float|string,
     *     longitude: int|float|string,
     *     speed_kmh?: int|float|string|null,
     *     recorded_at: string
     * } $attributes  JSON:API attributes already validated.
     */
    public static function fromAttributes(array $attributes): self
    {
        return new self(
            tripId: (int) $attributes['trip_id'],
            latitude: (float) $attributes['latitude'],
            longitude: (float) $attributes['longitude'],
            speedKmh: isset($attributes['speed_kmh']) ? (float) $attributes['speed_kmh'] : null,
            recordedAt: CarbonImmutable::parse($attributes['recorded_at'])->utc(),
        );
    }

    /**
     * Column values for a `gps_locations` row. `location` is intentionally
     * absent: the model derives the PostGIS point from latitude/longitude.
     *
     * @return array<string, mixed>
     */
    public function toDatabaseRow(): array
    {
        return [
            'trip_id' => $this->tripId,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'speed_kmh' => $this->speedKmh,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
