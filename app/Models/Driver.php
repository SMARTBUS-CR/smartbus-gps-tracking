<?php

namespace App\Models;

use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `drivers` table, owned by the Dashboard. A driver is the employment of an Auth
 * user in ONE company: `user_id` is unique per company, so the same person can
 * be a driver in several companies. To know whether a user drives a trip, start
 * from the trip (`trips.driver_id` -> `drivers.user_id`), never from the user.
 *
 * There is no user() relationship: `users` lives in the Auth microservice.
 *
 * @property string $id
 * @property string $company_id
 * @property string $user_id
 * @property string $license
 * @property string $status
 */
class Driver extends Model
{
    /** @use HasFactory<DriverFactory> */
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    protected $table = 'drivers';

    public $incrementing = false;

    protected $keyType = 'string';

    /** GPS never writes drivers. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'license_expires_at' => 'date',
            'employment_starts_on' => 'date',
            'employment_ends_on' => 'date',
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
