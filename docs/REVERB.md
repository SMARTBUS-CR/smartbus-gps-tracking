# Laravel Reverb - WebSockets del microservicio GPS

`laravel/reverb` v1.11 — servidor WebSocket que habla el **protocolo Pusher**.
Se instaló en el Step 8. El evento y el canal reales llegan en Steps 9 y 10.

## Cómo encaja

```
Driver Flutter ──HTTP──▶ API Gateway ──▶ GPS µservice ──▶ PostgreSQL/PostGIS
                                              │
                                     event(BusLocationUpdated)          (Step 9)
                                              │  Pusher HTTP API -> POST :8080/apps/{id}/events
                                              ▼
                                        Laravel Reverb  (proceso aparte, :8080)
                                              │  WebSocket (protocolo Pusher)
                                              ▼
                              Laravel Echo (Dart) en Passenger Flutter   (Step 11)
```

- El microservicio **publica** eventos; no mantiene sockets.
- Reverb es un **proceso separado** (`php artisan reverb:start`) que mantiene las
  conexiones WebSocket y reparte los mensajes.
- Backend ↔ Reverb: HTTP firmado con `REVERB_APP_SECRET`.
- Cliente ↔ Reverb: WebSocket autenticado con `REVERB_APP_KEY`.

## Configuración

| Archivo | Qué |
|---|---|
| `config/broadcasting.php` | conexión `reverb` (default). Lee `REVERB_APP_*`, `REVERB_HOST/PORT/SCHEME` |
| `config/reverb.php` | servidor: bind `REVERB_SERVER_HOST:PORT`, apps, scaling (Redis, **off**) |
| `config/cors.php` | `paths: ['gps/*', 'broadcasting/auth']` |
| `routes/channels.php` | autorización de canales (canal real en Step 10) |
| `.env` | `BROADCAST_CONNECTION=reverb` + bloque `REVERB_*` |

### Variables `.env`

| Variable | Rol |
|---|---|
| `REVERB_APP_ID/KEY/SECRET` | credenciales de la "app" Reverb. **Únicas por entorno.** |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | lo que el backend anuncia y a lo que se conecta el cliente |
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | bind del proceso `reverb:start` (`0.0.0.0:8080`) |
| `REVERB_SCALING_ENABLED=false` | sin Redis: un solo proceso, sin tablas |

> Regenerar credenciales: `php artisan reverb:install` o a mano. Nunca commitear `.env`.

## Correr en local (2 procesos en paralelo)

```powershell
php artisan serve                 # API HTTP  -> http://127.0.0.1:8000
php artisan reverb:start --debug   # WebSocket -> ws://127.0.0.1:8080
```

Otros comandos: `reverb:restart`, `reverb:start --host=0.0.0.0 --port=8080`.

### Windows / `pcntl`

`ext-pcntl` no existe en Windows. Reverb **funciona igual** para desarrollo local;
solo pierde el reinicio "elegante" por señales. `laravel/reverb` v1.11 **no** lo
exige en `composer require` (`composer check-platform-reqs` pasa limpio).
En producción (Linux) sí estará disponible.

## Queue

Los eventos `ShouldBroadcast` normalmente van a cola. Ahora `QUEUE_CONNECTION=sync`
→ se emiten inline. En el Step 9 se decide `ShouldBroadcastNow` (menor latencia,
ideal para GPS) vs. cola dedicada.

## Verificado en Step 8

- `php artisan reverb:start` arranca en `0.0.0.0:8080`.
- Round-trip Pusher: `$pusher->trigger(['trip.25'], 'BusLocationUpdated', [...])` → OK.
- `$pusher->get('/channels/trip.25')` → `{"occupied":false}` (API HTTP de Reverb responde).
- `php artisan about` → **Broadcasting: reverb**.
- Sin migraciones. Sin scaffolding JS (Echo se usa desde Flutter en Dart).
