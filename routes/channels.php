<?php

use App\Models\Trip;
use App\Support\Gateway\GatewayUser;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels - SmartBus GPS Microservice
|--------------------------------------------------------------------------
| Authorization for the PRIVATE channel  trip.{tripId}.
|
| Private (not public) because:
|   - the fleet's live positions must not be scrapeable anonymously,
|   - it forces subscribers through the API Gateway (verified identity),
|   - it allows cutting access per trip/role without touching the event.
|
| Since this service does NOT authenticate, the "user" is rebuilt by
| IdentifyFromGateway from the Gateway's headers (config/gateway.php).
| The subscription request arrives at  POST /api/gps/broadcasting/auth.
*/

Broadcast::channel('trip.{tripId}', function (?GatewayUser $user, string $tripId): bool {
    // No identity forwarded by the Gateway -> not authorized.
    if (! $user instanceof GatewayUser) {
        return false;
    }

    if (! ctype_digit($tripId)) {
        return false;
    }

    $trip = Trip::query()->find((int) $tripId, ['id', 'status']);

    // A trip can only be followed while it exists and is scheduled / in progress.
    return $trip !== null
        && in_array($trip->status, ['scheduled', 'in_progress'], true);
});
