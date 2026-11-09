<?php

namespace App\Models;

use App\Models\Concerns\HasLocationPoint;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * `gps_locations` table: defined by the Dashboard migrations, written by GPS
 * (the historical samples of each trip).
 *
 * Columns:
 *   id               uuid    (PK, no DB default: generated here by HasUuids, UUIDv7)
 *   trip_id          uuid    -> FK trips.id
 *   latitude         numeric(10,7)
 *   longitude        numeric(10,7)
 *   speed_kmh        numeric(8,2) nullable
 *   accuracy_meters  numeric(10,2) nullable  (device accuracy radius)
 *   recorded_at      timestamptz(6)  (when the device took the reading)
 *   received_at      timestamptz(6)  (when GPS received it, DB default now())
 *   location         geography(Point,4326) nullable
 *   UNIQUE (trip_id, recorded_at)    -> a duplicate reading cannot be stored twice
 *
 * `latitude`/`longitude` are the source of truth for displaying the position.
 * `location` is the derived PostGIS point, used for spatial queries (distance,
 * ST_DWithin, ETA...). It is kept in sync automatically by HasLocationPoint,
 * which writes longitude first.
 *
 * @property string $id
 * @property string $trip_id
 * @property float $latitude
 * @property float $longitude
 * @property float|null $speed_kmh
 * @property float|null $accuracy_meters
 * @property Carbon $recorded_at
 * @property Carbon|null $received_at
 */
class GpsLocation extends Model
{
    // The DB has no default for `id`: HasUuids generates a UUIDv7 on insert
    // (same version the DB team uses), which is also time-ordered.
    use HasFactory, HasLocationPoint, HasUuids;

    protected $table = 'gps_locations';

    /**
     * `location` is intentionally omitted: HasLocationPoint derives it, never
     * the request.
     */
    protected $fillable = [
        'trip_id',
        'latitude',
        'longitude',
        'speed_kmh',
        'accuracy_meters',
        'recorded_at',
        'received_at',
    ];

    /**
     * Keeps the raw EWKB binary of `location` out of JSON serialization.
     * Use the withLocationGeoJson() scope to expose it explicitly.
     */
    protected $hidden = [
        'location',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'speed_kmh' => 'float',
            'accuracy_meters' => 'float',
            'recorded_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * Coordinate in the standard GeoJSON order: [longitude, latitude].
     */
    protected function coordinates(): Attribute
    {
        return Attribute::get(fn (): array => [$this->longitude, $this->latitude])
            ->shouldCache();
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
