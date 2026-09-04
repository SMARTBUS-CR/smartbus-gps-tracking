# Integración Flutter — tiempo real (HU2 / HU3)

Qué necesita saber la app Flutter (repo aparte) para recibir la posición del bus.
El backend **no** cambia por esto: este documento es el contrato.

## 1. Qué manda el backend

**Evento:** `BusLocationUpdated` — [docs/EVENTS.md](EVENTS.md)
**Canal:** `private-trip.{tripId}` (privado) — [docs/CHANNELS.md](CHANNELS.md)
**Nombre a escuchar:** `.BusLocationUpdated` (el punto inicial ignora el namespace)

**Payload** (JSON del `data` del mensaje):

| Campo | Tipo | Uso en el mapa |
|---|---|---|
| `id` | int | id de la lectura. Descartar si `id` ≤ al último aplicado |
| `trip_id` | int | viaje al que pertenece |
| `bus_id` | int | qué bus (un viaje = un bus) |
| `latitude` | number | posición del marcador |
| `longitude` | number | posición del marcador |
| `speed_kmh` | number \| null | opcional (mostrar velocidad) |
| `recorded_at` | string ISO-8601 UTC | momento real de la lectura. Ordenar / descartar viejas |

```json
{
  "id": 14,
  "trip_id": 25,
  "bus_id": 1,
  "latitude": 9.9367,
  "longitude": -84.1067,
  "speed_kmh": 44.1,
  "recorded_at": "2026-09-03T18:00:00.000000Z"
}
```

Para "la posición mostrada es la última recibida" (AC HU3): quedarse siempre con
el mayor `recorded_at` (o `id`). Un evento con `recorded_at` anterior al aplicado
se ignora (llegan desordenados en redes móviles).

## 2. Cómo conecta Flutter

Paquetes Dart sugeridos:
- `laravel_echo`
- `pusher_channels_flutter` (conector; Reverb habla protocolo Pusher)

```dart
import 'package:laravel_echo/laravel_echo.dart';
import 'package:pusher_channels_flutter/pusher_channels_flutter.dart';

final echo = Echo<PusherChannelsFlutter, PusherChannel>(
  broadcaster: EchoBroadcasterType.Reverb,
  options: EchoOptions(
    key: const String.fromEnvironment('REVERB_APP_KEY'), // el KEY no es secreto
    host: 'gateway.smartbus.example',                    // host público de Reverb
    port: 443,
    forceTLS: true,
    // La auth del canal privado pasa por el API Gateway -> microservicio GPS:
    authEndpoint: 'https://gateway.smartbus.example/gps/broadcasting/auth',
    auth: EchoAuth(headers: {
      'Authorization': 'Bearer $userToken', // lo valida el Gateway, no el GPS service
      'Accept': 'application/json',
    }),
  ),
);
```

> Los valores `key / host / port / scheme` = las variables `REVERB_*` del backend.
> `authEndpoint` = `<gateway>/gps/broadcasting/auth` (con prefijo `gps`, igual que el resto).

## 3. Suscribirse a un viaje

```dart
void trackTrip(int tripId) {
  echo.private('trip.$tripId').listen('.BusLocationUpdated', (e) {
    final fix = BusLocation.fromJson(e is String ? jsonDecode(e) : e);
    _applyToMap(fix); // mueve el marcador, sin recargar
  });
}

void stopTracking(int tripId) => echo.leave('trip.$tripId');
```

Modelo:

```dart
class BusLocation {
  final int id, tripId, busId;
  final double latitude, longitude;
  final double? speedKmh;
  final DateTime recordedAt;

  BusLocation.fromJson(Map<String, dynamic> j)
      : id = j['id'],
        tripId = j['trip_id'],
        busId = j['bus_id'],
        latitude = (j['latitude'] as num).toDouble(),
        longitude = (j['longitude'] as num).toDouble(),
        speedKmh = (j['speed_kmh'] as num?)?.toDouble(),
        recordedAt = DateTime.parse(j['recorded_at']);
}
```

## 4. Reconexión automática (AC de HU2)

`pusher_channels_flutter` reconecta solo al perder la conexión (backoff interno).
Lo que la app debe hacer:

1. Escuchar el estado de conexión y, al volver a `connected`, **re-aplicar el
   estado**: las suscripciones se restablecen solas, pero los eventos emitidos
   mientras estabas desconectado **se perdieron** (Reverb no tiene historial).
2. Por eso, al reconectar, refrescar la posición desde el backend (ver §5).

```dart
pusher.onConnectionStateChange = (current, previous) {
  if (current == 'CONNECTED' && previous != 'CONNECTED') {
    _refreshTripPosition(); // volver a pedir el estado actual
  }
};
```

## 5. Estado inicial del mapa — GAP conocido para HU3

Cuando el pasajero abre el mapa **a mitad de viaje**, necesita la posición actual
*antes* del siguiente `BusLocationUpdated`. Hoy **no hay** endpoint REST para eso.

Cuando se implemente HU3 habrá que añadir al microservicio GPS algo como:

```
GET /gps/trips/{tripId}/location   -> última gps-location del viaje (JSON:API)
```

Mientras no exista, el mapa solo se puebla cuando llega el primer evento.
(No se implementa ahora: está fuera del alcance de esta etapa de preparación.)

## 6. Flujo completo HU3 (cuando se implemente)

```
abrir mapa del viaje 25
  → GET /gps/trips/25/location        (posición actual)   ← pendiente
  → echo.private('trip.25').listen('.BusLocationUpdated') (updates)
  → cada evento: si recorded_at > actual, mover marcador
  → al reconectar: repetir el GET
  → salir de la pantalla: echo.leave('trip.25')
```

## 7. Verificado en Step 11 (end-to-end real)

Suscriptor WebSocket (protocolo Pusher, Node) → `private-trip.1`:
1. conecta a Reverb, obtiene `socket_id`
2. `POST /gps/broadcasting/auth` con `X-Auth-User-Id` → `200` + firma
3. `pusher:subscribe` → `subscription_succeeded`
4. `POST /gps/locations` (coordenada real)
5. **recibe** `BusLocationUpdated` en `private-trip.1` con el payload exacto de arriba

Fila de prueba borrada. BD de operaciones intacta (ids 1,2,3).
