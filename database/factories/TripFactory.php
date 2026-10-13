<?php

namespace Database\Factories;

use App\Models\Bus;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Trip>
 */
class TripFactory extends Factory
{
    /**
     * A trip in progress (the state that accepts GPS readings and channel subscriptions).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'route_id' => Route::factory(),
            'bus_id' => Bus::factory(),
            'driver_id' => Driver::factory(),
            'status' => 'in_progress',
            'started_at' => now()->subHour(),
        ];
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => 'scheduled',
            'started_at' => null,
        ]);
    }

    /**
     * @param  \DateTimeInterface|null  $completedAt  Defaults to now.
     */
    public function completed(?\DateTimeInterface $completedAt = null): static
    {
        return $this->state(fn (): array => [
            'status' => 'completed',
            'completed_at' => $completedAt ?? now(),
        ]);
    }
}
