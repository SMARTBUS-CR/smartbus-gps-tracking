# Canales de broadcasting

## `private-trip.{tripId}`  (privado)

Definido en [`routes/channels.php`](../routes/channels.php).
Es el canal por el que viaja `BusLocationUpdated` (ver [EVENTS.md](EVENTS.md)).

### Por qué privado (no público)

| | Público (`Channel`) | **Privado (`PrivateChannel`)** ✅ |
|---|---|---|
| Suscripción | cualquiera con la URL del WS | requiere pasar `POST /gps/broadcasting/auth` |
| Anti-scraping de la flota | ❌ cualquiera lee todas las posiciones | ✅ hay que estar autenticado (via Gateway) |
| Control por viaje / rol | ❌ | ✅ en el callback, sin tocar el evento |
| Coste | 0 | 1 request de auth al suscribirse |

Las posiciones en vivo de los buses son datos operativos: no deben poder
consumirse de forma anónima ni masiva. Privado obliga a entrar por el
API Gateway y deja la puerta para reglas más finas más adelante.

### Cómo se autoriza sin sistema de auth propio

Este microservicio no autentica. El flujo:

```
Passenger Flutter (Echo)
   │  quiere private-trip.25
   ▼
POST <gateway>/gps/broadcasting/auth   { channel_name, socket_id }
   │  el Gateway valida el token y AÑADE cabeceras:
   │     X-Auth-User-Id: 42
   │     X-Auth-Roles: passenger
   │     X-Auth-Company-Id: 3
   ▼
GPS µservice  →  IdentifyFromGateway (middleware)
   │  reconstruye un GatewayUser desde esas cabeceras
   ▼
routes/channels.php  →  callback trip.{tripId}
   │  ¿hay GatewayUser?  ¿el viaje existe y está scheduled/in_progress?
   ▼
200 { "auth": "<key>:<firma>" }   ó   403
```

- Middleware: [`app/Http/Middleware/IdentifyFromGateway.php`](../app/Http/Middleware/IdentifyFromGateway.php)
- "Usuario": [`app/Support/Gateway/GatewayUser.php`](../app/Support/Gateway/GatewayUser.php) (no toca BD)
- Nombres de cabecera: [`config/gateway.php`](../config/gateway.php) (env `GATEWAY_HEADER_*`)
- La ruta `/gps/broadcasting/auth` se registra en `bootstrap/app.php` → `withBroadcasting()`
  con prefijo `gps` y **solo** el middleware `IdentifyFromGateway` (no el grupo `api`:
  la respuesta de Pusher no es JSON:API).

### Regla de autorización actual

```php
return $user instanceof GatewayUser
    && $trip !== null
    && in_array($trip->status, ['scheduled', 'in_progress'], true);
```

Cualquier usuario autenticado puede seguir cualquier viaje activo (un pasajero
en la parada necesita ver el bus sin tener un "boleto" de ese viaje concreto).
Si más adelante se quiere restringir (por compañía, por reserva…), se añade aquí.

### Contrato con el equipo del API Gateway

El Gateway, para **toda** request a `/gps/*` (incluida `/gps/broadcasting/auth`):
1. valida el token del usuario,
2. **elimina** cualquier `X-Auth-*` entrante del cliente,
3. añade `X-Auth-User-Id` (obligatoria) y `X-Auth-Roles`, `X-Auth-Company-Id` (opcionales),
4. reenvía `channel_name` y `socket_id` del body sin tocarlos.

La red Gateway ↔ GPS se asume privada (no expuesta a internet).

### Cliente (Flutter / Laravel Echo Dart) — se detalla en Step 11

```dart
Echo.private('trip.25')
    .listen('.BusLocationUpdated', (e) { /* mover marcador */ });
```
`authEndpoint` de Echo = `<gateway>/gps/broadcasting/auth`, con el token del usuario
en `Authorization` (lo consume el Gateway, no este servicio).

### Verificado en Step 10
- `broadcastOn()` → `private-trip.1`.
- `POST /gps/broadcasting/auth` con `X-Auth-User-Id` + viaje `in_progress` → `200 {"auth":"..."}`.
- Sin cabecera → `403`. Viaje `completed` → `403`. Viaje inexistente → `403`.
- Sin migraciones; BD de operaciones intacta.
