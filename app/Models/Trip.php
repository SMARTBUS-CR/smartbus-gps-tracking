<?php

namespace App\Models;

use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * `trips` table, owned by the Dashboard (smartbus-dashboard-admin migrations).
 *
 * GPS only READS trips. Starting and finishing a trip is done by the Dashboard
 * (POST /api/v1/operations/trips/{id}/start | complete); GPS accepts readings
 * only while the trip is in progress. The database enforces that each status
 * carries its timestamps (trips_execution_check):
 *
 *   scheduled   -> no actual_* timestamps
 *   in_progress -> actual_departure_at
 *   completed   -> actual_departure_at + actual_arrival_at
 *   cancelled   -> cancelled_at + cancellation_reason
 *
 * @property string $id
 * @property string $company_id
 * @property string $route_pattern_id
 * @property string $route_schedule_id
 * @property string $bus_id
 * @property string $driver_id
 * @property Carbon $service_date
 * @property string $status
 * @property Carbon $scheduled_departure_at
 * @property Carbon $scheduled_arrival_at
 * @property Carbon|null $actual_departure_at
 * @property Carbon|null $actual_arrival_at
 * @property array<string, mixed> $planned_route_snapshot
 * @property Carbon|null $cancelled_at
 */
class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'trips';

    /**
     * The PK is a UUID (string), not an autoincrement.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    /** GPS never writes trips (factories bypass this to build test rows). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'scheduled_departure_at' => 'datetime',
            'scheduled_arrival_at' => 'datetime',
            'actual_departure_at' => 'datetime',
            'actual_arrival_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'planned_route_snapshot' => 'array',
        ];
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<RoutePattern, $this>
     */
    public function routePattern(): BelongsTo
    {
        return $this->belongsTo(RoutePattern::class);
    }

    /**
     * @return BelongsTo<Bus, $this>
     */
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return HasMany<GpsLocation, $this>
     */
    public function gpsLocations(): HasMany
    {
        return $this->hasMany(GpsLocation::class);
    }
}
