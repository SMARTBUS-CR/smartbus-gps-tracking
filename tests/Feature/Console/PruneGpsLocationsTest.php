<?php

use App\Models\GpsLocation;
use App\Models\Trip;

use function Pest\Laravel\artisan;
use function Pest\Laravel\assertModelExists;
use function Pest\Laravel\assertModelMissing;

/*
| Housekeeping command that prunes old gps_locations of finished trips
| (config/gps.php). It never touches HU1 ingestion.
*/

it('deletes old readings of a finished trip but keeps recent ones', function () {
    $trip = Trip::factory()->completed(now()->subDays(200))->create();

    $old = GpsLocation::factory()->for($trip)->create(['recorded_at' => now()->subDays(90)]);
    $recent = GpsLocation::factory()->for($trip)->create(['recorded_at' => now()->subDay()]);

    artisan('gps:prune-locations', ['--days' => 60])->assertExitCode(0);

    assertModelMissing($old);
    assertModelExists($recent);
});

it('never prunes readings of a trip still in progress', function () {
    $veryOld = GpsLocation::factory()
        ->for(Trip::factory())
        ->create(['recorded_at' => now()->subDays(400)]);

    artisan('gps:prune-locations', ['--days' => 60])->assertExitCode(0);

    assertModelExists($veryOld);
});

it('only reports on a dry run, without deleting', function () {
    $trip = Trip::factory()->completed(now()->subDays(200))->create();
    $old = GpsLocation::factory()->for($trip)->create(['recorded_at' => now()->subDays(90)]);

    artisan('gps:prune-locations', ['--days' => 60, '--dry-run' => true])->assertExitCode(0);

    assertModelExists($old);
});
