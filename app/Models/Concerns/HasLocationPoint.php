<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Mantiene sincronizada una columna PostGIS `geography(Point,4326)` a partir de
 * las columnas numericas `latitude` / `longitude` del modelo.
 *
 * Regla de oro PostGIS: el punto es (X, Y) = (LONGITUD, LATITUD), en ese orden.
 * Invertirlos coloca el autobus en el oceano. Este trait centraliza ese orden
 * en un solo lugar para que nunca se equivoque el resto del codigo.
 *
 * NO crea la columna: solo escribe/lee la que ya existe en la BD.
 *
 * Requisitos del modelo que lo use:
 *   - columnas `latitude` (numeric) y `longitude` (numeric)
 *   - columna `location` geography(Point,4326)
 *
 * @mixin Model
 */
trait HasLocationPoint
{
    /**
     * Nombre de la columna geografica. Sobrescribible en el modelo si hiciera falta.
     */
    protected function locationColumn(): string
    {
        return 'location';
    }

    /**
     * Al guardar, deriva `location` de latitude/longitude.
     */
    public static function bootHasLocationPoint(): void
    {
        static::saving(function (Model $model): void {
            /** @var static $model */
            $model->syncLocationPoint();
        });
    }

    /**
     * Construye la expresion SQL del punto (longitud primero, latitud despues).
     */
    public function syncLocationPoint(): void
    {
        $lat = $this->getAttribute('latitude');
        $lng = $this->getAttribute('longitude');

        if ($lat === null || $lng === null) {
            return;
        }

        $this->setAttribute($this->locationColumn(), DB::raw(\sprintf(
            'ST_SetSRID(ST_MakePoint(%s, %s), 4326)::geography',
            self::floatLiteral($lng),  // X = longitud
            self::floatLiteral($lat),  // Y = latitud
        )));
    }

    /**
     * Convierte a literal numerico seguro (sin locale, sin inyeccion): un float
     * siempre se formatea como digitos + punto decimal.
     */
    protected static function floatLiteral(mixed $value): string
    {
        return \sprintf('%.8F', (float) $value);
    }

    /**
     * Scope: agrega `location` como GeoJSON string a los resultados.
     *
     *   GpsLocation::withLocationGeoJson()->find(1)->location_geojson
     *   // {"type":"Point","coordinates":[-78.4678,-0.1807]}   (lng, lat)
     */
    public function scopeWithLocationGeoJson(Builder $query, string $alias = 'location_geojson'): Builder
    {
        if (empty($query->getQuery()->columns)) {
            $query->select($this->qualifyColumn('*'));
        }

        return $query->selectRaw(
            'ST_AsGeoJSON('.$this->qualifyColumn($this->locationColumn()).') as '.$alias
        );
    }
}
