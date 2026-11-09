<?php

namespace App\Models;

use Database\Factories\RouteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `routes` table, owned by the Dashboard. A route groups its patterns
 * (directions / variants); trips point to a pattern, not to the route.
 *
 * Note: the class name collides with the Illuminate\Support\Facades\Route
 * facade. Always import App\Models\Route explicitly where the model is needed.
 *
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 */
class Route extends Model
{
    /** @use HasFactory<RouteFactory> */
    use HasFactory;

    protected $table = 'routes';

    public $incrementing = false;

    protected $keyType = 'string';

    /** GPS never writes routes. */
    protected $guarded = ['*'];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<RoutePattern, $this>
     */
    public function patterns(): HasMany
    {
        return $this->hasMany(RoutePattern::class);
    }
}
