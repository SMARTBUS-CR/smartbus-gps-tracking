<?php

namespace Database\Factories;

use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RouteSchedule>
 */
class RouteScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'route_pattern_id' => RoutePattern::factory(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'departure_time' => fake()->time('H:i:00'),
        ];
    }
}
