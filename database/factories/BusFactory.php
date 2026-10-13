<?php

namespace Database\Factories;

use App\Models\Bus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Bus>
 */
class BusFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'company_id' => Company::factory(),
            'plate_number' => Str::upper(fake()->unique()->bothify('???-###')),
            'unit_number' => (string) fake()->numberBetween(1, 999),
            'capacity' => 40,
            'is_active' => true,
        ];
    }
}
