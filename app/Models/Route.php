<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabla existente `routes` (NO administrada por este servicio).
 *
 * OJO: la clase se llama igual que la facade Illuminate\Support\Facades\Route.
 * Dentro de este proyecto siempre importar explicitamente App\Models\Route
 * donde se necesite el modelo.
 *
 * @property int    $id
 * @property int    $company_id
 * @property string $code
 * @property string $name
 * @property string $origin
 * @property string $destination
 * @property float|null $distance_km
 */
class Route extends Model
{
    protected $table = 'routes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'distance_km' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }
}
