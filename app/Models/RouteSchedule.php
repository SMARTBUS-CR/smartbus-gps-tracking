<?php

namespace App\Models;

use Database\Factories\RouteScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `route_schedules` table, owned by the Dashboard: a weekly departure of a
 * route pattern. Each trip is one service date of a schedule.
 *
 * @property string $id
 * @property string $route_pattern_id
 * @property int $day_of_week
 * @property string $departure_time
 */
class RouteSchedule extends Model
{
    /** @use HasFactory<RouteScheduleFactory> */
    use HasFactory;

    protected $table = 'route_schedules';

    public $incrementing = false;

    protected $keyType = 'string';

    /** GPS never writes schedules. */
    protected $guarded = ['*'];

    /**
     * @return BelongsTo<RoutePattern, $this>
     */
    public function routePattern(): BelongsTo
    {
        return $this->belongsTo(RoutePattern::class);
    }
}
