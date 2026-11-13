# Eventos del microservicio GPS

Todos se emiten en el canal privado `trip.{tripId}` (ver [CHANNELS.md](CHANNELS.md)).

| Evento | Cuándo | Payload |
|---|---|---|
| `BusLocationUpdated` | El conductor asignado envía una lectura aceptada (más nueva que la actual) | `id`, `trip_id`, `bus_id`, `latitude`, `longitude`, `speed_kmh`, `accuracy_meters`, `recorded_at` |

Las lecturas **duplicadas o atrasadas** no se emiten: el mapa nunca retrocede.

El fin del viaje lo marca el **Dashboard**; GPS no emite un evento de "viaje terminado".
Mientras el Dashboard no lo emita, el cliente lo detecta con `GET /trips/{id}/location`
(`status = completed`).

## BusLocationUpdated

Ubicación: [`app/Events/BusLocationUpdated.php`](../app/Events/BusLocationUpdated.php)

### Qué es
Evento de dominio que representa "llegó una nueva coordenada de un viaje".
Implementa `ShouldBroadcastNow` → Laravel lo publica en Reverb al instante (sin cola).

### Dónde se dispara
Único punto: [`GpsLocationService::record()`](../app/Services/GpsLocationService.php),
justo después de persistir la `gps_location`:

```php
$location = GpsLocation::create($fix->toDatabaseRow());
BusLocationUpdated::dispatch($location);
```

Flujo completo:
```
POST /api/gps/locations   (interna: /api/locations)
  → StoreGpsLocationRequest (valida)
  → GpsLocationService::record()
      → GpsLocation::create()            (+ location PostGIS via HasLocationPoint)
      → BusLocationUpdated::dispatch()
          → ShouldBroadcast → Reverb → Echo (Flutter)
  → 201 GpsLocationResource
```

### Canal
`broadcastOn()` → `new PrivateChannel('trip.'.$tripId)` → canal `private-trip.{id}`.
**Privado** — autorización y contrato con el Gateway en [CHANNELS.md](CHANNELS.md).

### Nombre para el cliente
`broadcastAs()` → `'BusLocationUpdated'`.
En Flutter: `.listen('.BusLocationUpdated', ...)` (el punto inicial ignora el namespace).

### Payload (`broadcastWith()`) — se afina en Step 11
```json
{
  "id": 9,
  "trip_id": 25,
  "bus_id": 1,
  "latitude": 10.4631,
  "longitude": -83.9921,
  "speed_kmh": 38.5,
  "recorded_at": "2026-09-03T15:00:00.000000Z"
}
```
`bus_id` no está en `gps_locations`: el evento carga `trip` para obtenerlo.
El constructor extrae solo escalares (no guarda el modelo) → payload simple. No se
encola: se emite en el mismo POST (ver abajo).

### Queue / latencia
Implementa **`ShouldBroadcastNow`**: se emite a Reverb dentro del POST, sin cola.
Con la cola (`queue:work --sleep=3`, un job a la vez) el pasajero veía el bus
segundos atrasado. Reverb corre al lado de la API (127.0.0.1), así que emitir en
línea tarda milisegundos y no depende del worker.

### Qué se guarda
Se **transmite** cada lectura (~5 s), pero en `gps_locations` solo quedan el
**inicio** (primera lectura del viaje) y el **fin** (la lectura con
`final: true`, que la app envía justo antes de "Finalizar recorrido"). Las demás
responden `202 broadcast_only`.

### Best-effort (no rompe HU1)
`GpsLocationService::broadcast()` envuelve el `dispatch()` en `try/catch + report()`.
Si Reverb está caído (o el broadcast falla), la `gps_location` **ya quedó guardada**
y el `POST /api/gps/locations` devuelve `201` igual. La transmisión es un extra, no un
requisito de la ingesta. Verificado: con Reverb apagado, el POST sigue dando `201`.

### Verificado en Step 9
- `Event::fake` → `record()` despacha `BusLocationUpdated` en `trip.1` con el payload correcto.
- `implements ShouldBroadcast` = sí.
- POST real (con Reverb corriendo) → `201`, sin errores en el flujo de broadcast.
- Fila de prueba borrada; BD de operaciones intacta (ids 1,2,3).

---

## TripArrivalsUpdated

### Qué es
Las llegadas estimadas (ETA) del bus a las paradas que le faltan. Mismo canal
`private-trip.{tripId}`; el cliente escucha `.TripArrivalsUpdated`.

### Dónde se calcula: fuera del POST del conductor
`php artisan gps:eta-watch` (programa `laravel-eta` de supervisord) recorre cada
~5 s (`ETA_REFRESH_SECONDS`) los viajes `in_progress` que tienen una posición
**nueva y reciente** (≤ 30 s), y para cada uno
(`App\Services\EtaOrchestrationService`):

1. Pide al Dashboard el contexto ETA del viaje
   (`GET /api/internal/v1/trips/{id}/tracking-context?projection=eta-v1`, en caché
   10 min): línea, distancia de cada parada y `route_revision`.
2. Calcula el avance del bus sobre esa línea con la **misma proyección que el
   Dashboard**, así cada `remaining_distance_m` es exactamente
   `distancia de la parada − avance`, como valida el servicio ETA.
3. `POST {ETA_SERVICE_URL}/trips/{id}/arrivals` (contrato v1) con las paradas
   que faltan.
4. Emite `TripArrivalsUpdated`.

El `POST /api/gps/locations` **no llama a nadie**: solo deja el token del
conductor en la caché (cifrado, 10 min), porque el Dashboard y el ETA autorizan
al usuario activo. Si el Dashboard o el ETA están lentos o caídos, solo se
atrasan o faltan los ETA; la posición del bus sigue igual.

Sin `ETA_SERVICE_URL` o `DASHBOARD_URL` todo esto está apagado.

### Payload
```json
{
  "trip_id": "01a0…",
  "arrivals": [
    {
      "stop_occurrence_id": "01a0…", "occurrence_id": "01a0…",
      "stop_id": "01a0…", "stop_sequence": 2,
      "remaining_distance_m": 222.4,
      "eta_seconds": 23, "arrival_at": "2026-11-13T18:00:23Z",
      "planned_arrival_at": "2026-11-13T18:01:00Z",
      "method": "distance_speed", "quality_reason": "current_speed"
    }
  ],
  "calculated_at": "2026-11-13T18:00:00+00:00"
}
```
`method`: `distance_speed` (en vivo), `arrived`, `scheduled_reference` (solo
itinerario: `eta_seconds` y `arrival_at` nulos, usar `planned_arrival_at`) o
`unavailable`.

### Cuándo no hay ETA
- El recorrido no tiene línea por calles ("Calculate Route" en el Dashboard): el
  Dashboard responde 422 y se vuelve a intentar al minuto.
- La posición tiene más de 30 s (señal perdida).
- `409` del ETA (cambió el plan o el viaje terminó): se vuelve a pedir el contexto.
