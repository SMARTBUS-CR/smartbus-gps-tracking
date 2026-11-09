<?php

namespace Database\Factories;

use App\Models\Route;
use App\Models\RoutePattern;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RoutePattern>
 */
class RoutePatternFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'route_id' => Route::factory(),
            'code' => Str::upper(fake()->unique()->bothify('P-###??')),
            'name' => 'Ida',
            'headsign' => fake()->city(),
        ];
    }
}
