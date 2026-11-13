<?php

namespace App\Console\Commands;

use App\Services\EtaOrchestrationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Keeps the arrival estimates (ETA) of the trips in progress up to date.
 *
 * Runs as its own supervisord program, apart from the API: the driver's GPS
 * request never waits for the Dashboard or the ETA service, and if this
 * process fails, positions keep flowing as always. Every pass handles only
 * trips with a new position (see EtaOrchestrationService::refreshTrip).
 *
 * Without ETA_SERVICE_URL / DASHBOARD_URL it just idles.
 */
class WatchTripArrivals extends Command
{
    protected $signature = 'gps:eta-watch
        {--once : Run a single pass and exit.}
        {--max-time=3600 : Seconds before exiting (supervisord starts it again).}';

    protected $description = 'Calculate and broadcast arrival estimates of the trips in progress.';

    /** Seconds between checks while the feature is not configured. */
    private const IDLE_SECONDS = 60;

    public function handle(EtaOrchestrationService $eta): int
    {
        $deadline = microtime(true) + max(1, (int) $this->option('max-time'));

        if (! $eta->isEnabled()) {
            $this->components->warn('ETA disabled: set ETA_SERVICE_URL and DASHBOARD_URL to enable it.');
        }

        do {
            $startedAt = microtime(true);
            $enabled = $eta->isEnabled();

            if ($enabled) {
                try {
                    $eta->refreshActiveTrips();
                } catch (Throwable $e) {
                    report($e); // e.g. database unreachable: try again next pass
                }
            }

            if ($this->option('once')) {
                break;
            }

            $interval = $enabled ? max(1.0, (float) config('services.eta.interval', 5)) : self::IDLE_SECONDS;
            $wait = min($interval - (microtime(true) - $startedAt), $deadline - microtime(true));
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        } while (microtime(true) < $deadline);

        return self::SUCCESS;
    }
}
