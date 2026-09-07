<?php

namespace App\Models;

use App\Models\Concerns\HasLocationPoint;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Existing `gps_locations` table (NOT managed by this service).
 *
 * Columns (see docs/DATABASE.md):
 *   id           bigint (identity)
 *   trip_id      bigint  -> FK trips.id
 *   latitude     numeric(10,7)
 *   longitude    numeric(10,7)
 *   speed_kmh    numeric(8,2) nullable
 *   recorded_at  timestamp(0)  (stored in UTC)
 *   location     geography(Point,4326) nullable
 *   created_at / updated_at
 *
 * `latitude`/`longitude` are the source of truth for displaying the position.
 * `location` is the derived PostGIS point, used for spatial queries (distance,
 * ST_DWithin, ETA...). It is kept in sync automatically by HasLocationPoint,
 * which writes longitude first.
 *
 * @property int $id
 * @property int $trip_id
 * @property float $latitude
 * @property float $longitude
 * @property float|null $speed_kmh
 * @property Carbon $recorded_at
 */
class GpsLocation extends Model
{
    use HasLocationPoint;

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
        'recorded_at',
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
            'trip_id' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'speed_kmh' => 'float',
            'recorded_at' => 'datetime',
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
