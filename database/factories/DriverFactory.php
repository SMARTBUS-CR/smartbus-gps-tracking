<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            // Opaque id of the Auth microservice user.
            'user_id' => (string) Str::uuid7(),
            'company_id' => Company::factory(),
            'license' => Str::upper(fake()->bothify('LIC-########')),
            'status' => 'active',
        ];
    }
}
