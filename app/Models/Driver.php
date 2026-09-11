<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// User is intentionally not imported: the `users` table does not live in this DB
// (it belongs to the Auth microservice).

/**
 * Existing `drivers` table (NOT managed by this service).
 *
 * Columns (see docs/DATABASE.md):
 *   id          uuid    (PK, no autoincrement, no DB default)
 *   user_id     bigint  UNIQUE  -> opaque id from the Auth microservice (there is
 *                                 no `users` table and no FK here). Exposed as-is
 *                                 as a plain attribute.
 *   company_id  bigint  -> FK companies.id
 *   license     varchar(30)
 *   status      varchar(20)  default 'active'
 *   created_at / updated_at
 *
 * @property string $id
 * @property int $user_id
 * @property int $company_id
 * @property string $license
 * @property string $status
 */
class Driver extends Model
{
    protected $table = 'drivers';

    /**
     * The PK is a UUID (string), not an autoincrement.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'company_id',
        'license',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'company_id' => 'integer',
        ];
    }

    /*
     * NOTE: this microservice does NOT create drivers (another team owns them).
     * If generating the UUID on insert is ever needed, add:
     *
     *   use Illuminate\Database\Eloquent\Concerns\HasUuids;
     *   use HasUuids;
     *
     * after checking the UUID version matches the one the DB team uses.
     *
     * There is deliberately no user() relationship: the `users` table lives in
     * the Auth microservice's DB. `user_id` is exposed as an integer attribute
     * and whoever needs the user data (Gateway / frontend / Auth service)
     * resolves it.
     */

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
