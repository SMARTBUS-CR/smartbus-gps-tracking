<?php

namespace App\Events;

use App\Models\Trip;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when the driver finishes the trip: the passengers following it on the
 * private channel `trip.{tripId}` stop waiting for new positions.
 *
 * Client: `.listen('.TripTrackingEnded', ...)`.
 */
class TripTrackingEnded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $tripId;

    public string $status;

    /** ISO-8601 in UTC. */
    public string $endedAt;

    public function __construct(Trip $trip)
    {
        $this->tripId = $trip->id;
        $this->status = $trip->status;
        $this->endedAt = ($trip->actual_arrival_at ?? now())->toISOString();
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('trip.'.$this->tripId)];
    }

    public function broadcastAs(): string
    {
        return 'TripTrackingEnded';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'trip_id' => $this->tripId,
            'status' => $this->status,
            'ended_at' => $this->endedAt,
        ];
    }
}
