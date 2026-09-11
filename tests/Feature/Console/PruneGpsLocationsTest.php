<?php

namespace Tests\Feature\Console;

use App\Models\GpsLocation;
use App\Models\Trip;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Housekeeping command that prunes old gps_locations of finished trips
 * (config/gps.php). Does not touch HU1 ingestion — see
 * tests/Feature/Api/StoreGpsLocationTest.php for that contract.
 *
 * No migrations: uses the real operations DB (pgsql from .env), every test
 * runs inside a rolled-back transaction (DatabaseTransactions), same as
 * StoreGpsLocationTest.
 */
class PruneGpsLocationsTest extends TestCase
{
    use DatabaseTransactions;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $trip = Trip::query()->first();
        if ($trip === null) {
            $this->markTestSkipped('The operations DB has no trips to test against.');
        }
        $this->trip = $trip;
    }

    public function test_deletes_old_readings_of_a_finished_trip_but_keeps_recent_ones(): void
    {
        $this->trip->forceFill(['completed_at' => Carbon::now()->subDays(200)])->save();

        $old = GpsLocation::create([
            'trip_id' => $this->trip->id,
            'latitude' => 9.93,
            'longitude' => -84.08,
            'speed_kmh' => 20,
            'recorded_at' => Carbon::now()->subDays(90),
        ]);
        $recent = GpsLocation::create([
            'trip_id' => $this->trip->id,
            'latitude' => 9.93,
            'longitude' => -84.08,
            'speed_kmh' => 20,
            'recorded_at' => Carbon::now()->subDays(1),
        ]);

        $this->artisan('gps:prune-locations', ['--days' => 60])
            ->assertExitCode(0);

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_never_prunes_readings_of_a_trip_still_in_progress(): void
    {
        $this->trip->forceFill(['completed_at' => null])->save();

        $veryOld = GpsLocation::create([
            'trip_id' => $this->trip->id,
            'latitude' => 9.93,
            'longitude' => -84.08,
            'speed_kmh' => 20,
            'recorded_at' => Carbon::now()->subDays(400),
        ]);

        $this->artisan('gps:prune-locations', ['--days' => 60])
            ->assertExitCode(0);

        $this->assertModelExists($veryOld);
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $this->trip->forceFill(['completed_at' => Carbon::now()->subDays(200)])->save();

        $old = GpsLocation::create([
            'trip_id' => $this->trip->id,
            'latitude' => 9.93,
            'longitude' => -84.08,
            'speed_kmh' => 20,
            'recorded_at' => Carbon::now()->subDays(90),
        ]);

        $this->artisan('gps:prune-locations', ['--days' => 60, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertModelExists($old);
    }
}
