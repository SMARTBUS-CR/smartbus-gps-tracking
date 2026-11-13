<?php

namespace App\Jobs;

use App\Data\GpsFix;
use App\Services\EtaOrchestrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CalculateTripArrivalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $tripId,
        public GpsFix $fix,
        public ?string $bearerToken = null,
    ) {}

    public function handle(EtaOrchestrationService $service): void
    {
        $service->calculate($this->tripId, $this->fix, $this->bearerToken);
    }
}
