<?php

use App\Models\Trip;
use App\Support\Gateway\GatewayUser;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

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
| Since this service does NOT own users, the "user" is resolved by
| IdentifyFromGateway from the forwarded Bearer token (config/gateway.php).
| The subscription request arrives at  POST /api/gps/broadcasting/auth.
*/

Broadcast::channel('trip.{tripId}', function (?GatewayUser $user, string $tripId): bool {
    // No valid Bearer token (Authentication Service) -> not authorized.
    if (! $user instanceof GatewayUser) {
        return false;
    }

    // A non-UUID can never match a trip (and would make Postgres throw).
    if (! Str::isUuid($tripId)) {
        return false;
    }

    $trip = Trip::query()->find($tripId, ['id', 'status']);

    // A trip can only be followed while it exists and is scheduled / in progress.
    return $trip !== null
        && in_array($trip->status, ['scheduled', 'in_progress'], true);
});
