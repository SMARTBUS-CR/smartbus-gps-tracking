<?php

namespace App\Models;

use Database\Factories\RoutePatternFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `route_patterns` table, owned by the Dashboard: one direction / variant of a
 * route (e.g. "San José → Heredia"). Trips run a pattern.
 *
 * @property string $id
 * @property string $route_id
 * @property string $code
 * @property string $name
 * @property string $headsign
 */
class RoutePattern extends Model
{
    /** @use HasFactory<RoutePatternFactory> */
    use HasFactory;

    protected $table = 'route_patterns';

    public $incrementing = false;

    protected $keyType = 'string';

    /** GPS never writes route patterns. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'route_geometry' => 'array',
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
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }
}
