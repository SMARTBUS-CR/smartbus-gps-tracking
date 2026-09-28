<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Existing `companies` table (NOT managed by this service).
 * Only used as the inverse side of relationships (Driver/Bus/Route belongsTo Company).
 *
 * @property string $id
 * @property string $name
 */
class Company extends Model
{
    protected $table = 'companies';

    /**
     * The PK is a UUID (string), not an autoincrement.
     */
    public $incrementing = false;

    protected $keyType = 'string';

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
