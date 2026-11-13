<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a PostGIS `geography(Point,4326)` column in sync with the model's
 * numeric `latitude` / `longitude` columns.
 *
 * PostGIS golden rule: a point is (X, Y) = (LONGITUDE, LATITUDE), in that order.
 * Swapping them puts the bus in the ocean. This trait keeps that order in one
 * place so the rest of the code never has to get it right.
 *
 * It does NOT create the column: it only reads/writes the one already in the DB.
 *
 * Requirements of the model using it:
 *   - `latitude` (numeric) and `longitude` (numeric) columns
 *   - `location` geography(Point,4326) column
 *
 * @mixin Model
 */
trait HasLocationPoint
{
    /**
     * Name of the geography column. Override in the model if needed.
     */
    protected function locationColumn(): string
    {
        return 'location';
    }

    /**
     * On save, derive `location` from latitude/longitude.
     */
    public static function bootHasLocationPoint(): void
    {
        static::saving(function (Model $model): void {
            /** @var static $model */
            $model->syncLocationPoint();
        });
    }

    /**
     * Builds the SQL expression for the point (longitude first, latitude second).
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
            self::floatLiteral($lng),  // X = longitude
            self::floatLiteral($lat),  // Y = latitude
        )));
    }

    /**
     * Formats a value as a safe numeric literal (locale-independent, no injection):
     * a float always renders as digits + decimal point.
     */
    protected static function floatLiteral(mixed $value): string
    {
        return \sprintf('%.8F', (float) $value);
    }

    /**
     * Scope: adds `location` as a GeoJSON string to the results.
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
