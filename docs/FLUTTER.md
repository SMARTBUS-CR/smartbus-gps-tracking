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
| `id` | string (UUID) \| null | id de la lectura guardada (`null` si se transmitió sin guardarse en el historial) |
| `trip_id` | string (UUID) | viaje al que pertenece |
| `bus_id` | string (UUID) | qué bus (un viaje = un bus) |
| `latitude` | number | posición del marcador |
| `longitude` | number | posición del marcador |
| `speed_kmh` | number \| null | opcional (mostrar velocidad) |
| `accuracy_meters` | number \| null | precisión del GPS del conductor (opcional) |
| `recorded_at` | string ISO-8601 UTC | momento real de la lectura. Ordenar / descartar viejas |

```json
{
  "id": "01a0e959-ed71-709e-a5a9-6c1436b11014",
  "trip_id": "01a09332-3457-7315-885e-4ebb218f7262",
  "bus_id": "01a09332-1111-7315-885e-4ebb218f7262",
  "latitude": 9.9367,
  "longitude": -84.1067,
  "speed_kmh": 44.1,
  "accuracy_meters": 8.5,
  "recorded_at": "2026-09-03T18:00:00.000000Z"
}
```

Para "la posición mostrada es la última recibida" (AC HU3): quedarse siempre con
el mayor `recorded_at`. Un evento con `recorded_at` anterior al aplicado
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
    host: 'smartbus-api-gateway.onrender.com', // el nginx del Gateway reenvía /app/* a Reverb
    port: 443,
    forceTLS: true,
    // WebSocket y autorización del canal privado pasan por el Gateway:
    authEndpoint: 'https://smartbus-api-gateway.onrender.com/api/gps/broadcasting/auth',
    auth: EchoAuth(headers: {
      'Authorization': 'Bearer $userToken', // lo valida el Gateway y lo reenvía al GPS service
      'Accept': 'application/json',
    }),
  ),
);
```

> `key / port / scheme` = las variables `REVERB_*` del backend.
> `host` = el **Gateway**: su nginx reenvía `wss://<gateway>/app/<KEY>` a Reverb (el proxy
> HTTP de Laravel no hace el upgrade a WebSocket, pero nginx sí).
> `authEndpoint` = `<gateway>/api/gps/broadcasting/auth`. Cualquier usuario autenticado
> puede seguir un viaje programado o en curso; se rechaza sin token, con token inválido o
> si el viaje terminó, se canceló o no existe.
> Ver [GATEWAY.md §4](GATEWAY.md).

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

## 5. Estado inicial del mapa

Cuando el pasajero abre el mapa **a mitad de viaje**, necesita la posición actual
*antes* del siguiente `BusLocationUpdated`:

```
GET /api/gps/trips/{tripId}/location
  → 200  { "data": { "type": "trip-trackings", "id": "<tripId>",
           "attributes": {
             "trip_id": "<tripId>",
             "status": "in_progress",          // scheduled | in_progress | completed | cancelled
             "freshness": "live",              // live | stale | no_signal | not_tracking
             "age_seconds": 4,                 // null si no hay posición
             "position": {                     // null hasta la primera lectura
               "latitude": 9.93, "longitude": -84.08, "speed_kmh": 38.5,
               "accuracy_meters": 8.5, "recorded_at": "2026-11-09T15:00:00.000000Z" } } } }
  → 404  si el viaje no existe
```

Qué mostrar según `freshness`:

| freshness | Significa | En el mapa |
|---|---|---|
| `live` | posición de los últimos 30 s | bus normal |
| `stale` | posición de hace 30 s – 5 min | bus en gris / "hace X min" |
| `no_signal` | sin posición o de hace más de 5 min | "sin señal" |
| `not_tracking` | el viaje no está en curso | "aún no sale" / "viaje terminado" |

`position` trae siempre la **última posición aceptada** (aunque no se haya guardado en el
historial), así que coincide con el último `BusLocationUpdated`.

## 6. Flujo completo HU3

```
abrir mapa del viaje 25
  → GET  /api/gps/trips/25/location                        (posición actual)
  → echo.private('trip.25').listen('.BusLocationUpdated')  (updates)
  → cada evento: si recorded_at > actual, mover marcador (sin recargar)
  → echo.private('trip.25').listen('.TripTrackingEnded')   (viaje terminado: dejar de esperar)
  → al reconectar: repetir el GET
  → salir de la pantalla: echo.leave('trip.25')
```

## 7. Verificado end-to-end (real)

Suscriptor WebSocket (protocolo Pusher, Node) → `private-trip.1`:
1. conecta a Reverb, obtiene `socket_id`
2. `POST /api/gps/broadcasting/auth` con `Authorization: Bearer <token>` → `200` + firma
3. `pusher:subscribe` → `subscription_succeeded`
4. `POST /api/gps/locations` (coordenada real)
5. **recibe** `BusLocationUpdated` en `private-trip.1` con el payload exacto de arriba

`GET /api/gps/trips/{id}/location` → devuelve la última lectura; `404` limpio si no hay.
Filas de prueba borradas. BD de operaciones intacta (ids 1,2,3).
