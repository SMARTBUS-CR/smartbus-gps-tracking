<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Route;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Route>
 */
class RouteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'company_id' => Company::factory(),
            'code' => Str::upper(fake()->bothify('R-###')),
            'name' => fake()->city().' - '.fake()->city(),
            'origin' => fake()->city(),
            'destination' => fake()->city(),
            'is_active' => true,
        ];
    }
}
