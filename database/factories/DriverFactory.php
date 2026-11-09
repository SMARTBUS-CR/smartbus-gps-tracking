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
            'company_id' => Company::factory(),
            // Opaque id of the Auth microservice user.
            'user_id' => (string) Str::uuid7(),
            'license' => Str::upper(fake()->unique()->bothify('LIC-########')),
            'license_expires_at' => now()->addYears(2)->toDateString(),
            'employment_starts_on' => now()->subYear()->toDateString(),
            'status' => Driver::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => 'inactive']);
    }
}
