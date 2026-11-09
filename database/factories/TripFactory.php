<?php

namespace Database\Factories;

use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\Trip;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Builds a complete trip like the Dashboard plans it: the route pattern,
 * schedule, bus and driver all belong to the trip's company (the database
 * enforces it for the bus and the driver).
 *
 * @extends Factory<Trip>
 */
class TripFactory extends Factory
{
    /**
     * A trip in progress (the state that accepts GPS readings).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $departure = now()->subHour();

        return [
            'id' => (string) Str::uuid7(),
            'company_id' => Company::factory(),
            'route_pattern_id' => fn (array $trip) => RoutePattern::factory()
                ->for(Route::factory()->state(['company_id' => $trip['company_id']]))
                ->create()->id,
            'route_schedule_id' => fn (array $trip) => RouteSchedule::factory()
                ->create(['route_pattern_id' => $trip['route_pattern_id']])->id,
            'bus_id' => fn (array $trip) => Bus::factory()
                ->create(['company_id' => $trip['company_id']])->id,
            'driver_id' => fn (array $trip) => Driver::factory()
                ->create(['company_id' => $trip['company_id']])->id,
            'service_date' => $departure->toDateString(),
            'status' => Trip::STATUS_IN_PROGRESS,
            'scheduled_departure_at' => $departure,
            'scheduled_arrival_at' => $departure->copy()->addHours(2),
            'actual_departure_at' => $departure,
            'planned_route_snapshot' => [
                'type' => 'LineString',
                'coordinates' => [[-84.0907, 9.9281], [-84.1165, 9.9981]],
            ],
        ];
    }

    /**
     * Trip driven by the Auth user [$userId] (creates its driver profile).
     */
    public function drivenBy(string $userId): static
    {
        return $this->state([
            // Lazy: resolved after company_id, so the driver joins the trip's company.
            'driver_id' => fn (array $trip) => Driver::factory()->create([
                'company_id' => $trip['company_id'],
                'user_id' => $userId,
            ])->id,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => Trip::STATUS_SCHEDULED,
            'actual_departure_at' => null,
        ]);
    }

    /**
     * @param  DateTimeInterface|null  $arrivedAt  Defaults to now.
     */
    public function completed(?DateTimeInterface $arrivedAt = null): static
    {
        return $this->state(function () use ($arrivedAt): array {
            $arrival = $arrivedAt ? Carbon::instance($arrivedAt) : now();

            return [
                'status' => Trip::STATUS_COMPLETED,
                'actual_departure_at' => $arrival->copy()->subHours(2),
                'actual_arrival_at' => $arrival,
            ];
        });
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => Trip::STATUS_CANCELLED,
            'actual_departure_at' => null,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Bus out of service',
        ]);
    }
}
