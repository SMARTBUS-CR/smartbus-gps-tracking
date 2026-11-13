<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when estimated arrivals at future route stops are updated.
 *
 * Broadcast live to passengers tracking the trip via Reverb (WebSocket).
 */
class TripArrivalsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public string $tripId;

    public array $arrivals;

    public string $calculatedAt;

    /**
     * @param  array<int, array<string, mixed>>  $arrivals
     */
    public function __construct(string $tripId, array $arrivals, ?string $calculatedAt = null)
    {
        $this->tripId = $tripId;
        $this->arrivals = $arrivals;
        $this->calculatedAt = $calculatedAt ?? now()->toIso8601String();
    }

    /**
     * Private channel of the trip. Subscriptions are authorized in
     * routes/channels.php via POST /api/gps/broadcasting/auth.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('trip.'.$this->tripId),
        ];
    }

    /**
     * Event name the client listens for: `.listen('.TripArrivalsUpdated', ...)`.
     */
    public function broadcastAs(): string
    {
        return 'TripArrivalsUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'trip_id' => $this->tripId,
            'arrivals' => $this->arrivals,
            'calculated_at' => $this->calculatedAt,
        ];
    }
}
