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
El constructor extrae solo escalares (no guarda el modelo) → serialización trivial en cola.

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
