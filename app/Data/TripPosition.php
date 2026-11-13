<?php

namespace App\Data;

use App\Models\GpsLocation;
use Carbon\CarbonImmutable;

/**
 * The bus's CURRENT position on a trip: the most recent accepted reading,
 * whether or not it was persisted to `gps_locations` (the history only keeps
 * samples, see config/gps.php).
 */
final readonly class TripPosition
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public ?float $speedKmh,
        public ?float $accuracyMeters,
        public CarbonImmutable $recordedAt,
    ) {}

    public static function fromFix(GpsFix $fix): self
    {
        return new self(
            $fix->latitude,
            $fix->longitude,
            $fix->speedKmh,
            $fix->accuracyMeters,
            $fix->recordedAt,
        );
    }

    public static function fromLocation(GpsLocation $location): self
    {
        return new self(
            (float) $location->latitude,
            (float) $location->longitude,
            $location->speed_kmh,
            $location->accuracy_meters,
            CarbonImmutable::instance($location->recorded_at)->utc(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'speed_kmh' => $this->speedKmh,
            'accuracy_meters' => $this->accuracyMeters,
            'recorded_at' => $this->recordedAt->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (float) $data['latitude'],
            (float) $data['longitude'],
            isset($data['speed_kmh']) ? (float) $data['speed_kmh'] : null,
            isset($data['accuracy_meters']) ? (float) $data['accuracy_meters'] : null,
            CarbonImmutable::parse($data['recorded_at'])->utc(),
        );
    }
}
