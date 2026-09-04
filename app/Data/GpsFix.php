<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * Una lectura GPS ya validada, lista para persistir / transmitir.
 *
 * Objeto inmutable que desacopla el transporte (HTTP/JSON:API) de la logica
 * de negocio: el controller construye un GpsFix desde el request y el service
 * solo conoce este tipo, no la forma del request.
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
     * } $attributes  Atributos JSON:API ya validados.
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
     * Forma de fila para gps_locations (sin `location`: la deriva el modelo).
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
