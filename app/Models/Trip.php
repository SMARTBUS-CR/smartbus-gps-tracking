<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Existing `trips` table (NOT managed by this service).
 *
 * Columns (see docs/DATABASE.md):
 *   id            uuid    (PK, no DB default)
 *   route_id      uuid    -> FK routes.id
 *   bus_id        uuid    -> FK buses.id
 *   driver_id     uuid    -> FK drivers.id
 *   status        varchar(20)  default 'scheduled'
 *   started_at / completed_at   timestamp(0) nullable
 *   created_at / updated_at
 *
 * @property string $id
 * @property string $route_id
 * @property string $bus_id
 * @property string $driver_id
 * @property string $status
 */
class Trip extends Model
{
    protected $table = 'trips';

    /**
     * The PK is a UUID (string), not an autoincrement.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'route_id',
        'bus_id',
        'driver_id',
        'status',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Route, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /**
     * @return BelongsTo<Bus, $this>
     */
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return HasMany<GpsLocation, $this>
     */
    public function gpsLocations(): HasMany
    {
        return $this->hasMany(GpsLocation::class);
    }
}
