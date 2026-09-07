<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Existing `companies` table (NOT managed by this service).
 * Only used as the inverse side of relationships (Driver/Bus/Route belongsTo Company).
 *
 * @property int $id
 * @property string $name
 */
class Company extends Model
{
    protected $table = 'companies';

    protected $guarded = ['id'];

    /**
     * @return HasMany<Driver, $this>
     */
    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    /**
     * @return HasMany<Bus, $this>
     */
    public function buses(): HasMany
    {
        return $this->hasMany(Bus::class);
    }

    /**
     * @return HasMany<Route, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }
}
