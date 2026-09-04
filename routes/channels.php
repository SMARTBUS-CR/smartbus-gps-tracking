<?php

use App\Models\Trip;
use App\Support\Gateway\GatewayUser;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels - SmartBus GPS Microservice
|--------------------------------------------------------------------------
| Autorizacion del canal PRIVADO  trip.{tripId}.
|
| Privado (no publico) porque:
|   - las posiciones en vivo de la flota no deben poder scrapearse anonimamente,
|   - obliga a pasar por el API Gateway (identidad verificada) para suscribirse,
|   - permite cortar el acceso por viaje/rol sin tocar el evento.
|
| Como este servicio NO autentica, el "usuario" lo reconstruye
| IdentifyFromGateway desde las cabeceras del Gateway (config/gateway.php).
| La request de suscripcion llega a  POST /gps/broadcasting/auth.
*/

Broadcast::channel('trip.{tripId}', function (?GatewayUser $user, string $tripId): bool {
    // Sin identidad reenviada por el Gateway -> no se autoriza.
    if (! $user instanceof GatewayUser) {
        return false;
    }

    if (! ctype_digit($tripId)) {
        return false;
    }

    $trip = Trip::query()->find((int) $tripId, ['id', 'status']);

    // Solo se puede seguir un viaje que existe y esta en curso / programado.
    return $trip !== null
        && in_array($trip->status, ['scheduled', 'in_progress'], true);
});
