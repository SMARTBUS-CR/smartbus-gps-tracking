<?php

namespace App\Models;

use App\Models\Concerns\HasLocationPoint;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla existente `gps_locations` (NO administrada por este servicio).
 *
 * Columnas (ver docs/DATABASE.md):
 *   id           bigint (identity)
 *   trip_id      bigint  -> FK trips.id
 *   latitude     numeric(10,7)
 *   longitude    numeric(10,7)
 *   speed_kmh    numeric(8,2) nullable
 *   recorded_at  timestamp(0)  (se guarda en UTC)
 *   location     geography(Point,4326) nullable
 *   created_at / updated_at
 *
 * `latitude`/`longitude` son la fuente de verdad para mostrar la posicion.
 * `location` es el punto PostGIS derivado, para consultas espaciales (distancia,
 * ST_DWithin, ETA...). Se sincroniza solo via HasLocationPoint: longitud primero.
 *
 * @property int         $id
 * @property int         $trip_id
 * @property float       $latitude
 * @property float       $longitude
 * @property float|null  $speed_kmh
 * @property \Illuminate\Support\Carbon $recorded_at
 */
class GpsLocation extends Model
{
    use HasLocationPoint;

    protected $table = 'gps_locations';

    /**
     * `location` NO va aqui: lo deriva HasLocationPoint, nunca el request.
     */
    protected $fillable = [
        'trip_id',
        'latitude',
        'longitude',
        'speed_kmh',
        'recorded_at',
    ];

    /**
     * Evita que el binario EWKB de `location` se filtre al serializar a JSON.
     * Para exponerlo usar el scope withLocationGeoJson().
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
     * Coordenada en el orden GeoJSON estandar: [longitud, latitud].
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
