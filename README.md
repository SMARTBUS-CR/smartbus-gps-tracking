# SmartBus GPS — Microservicio

Microservicio de **rastreo GPS** de la plataforma SMARTBUS GLOBAL (transporte público
basado en microservicios). Recibe las coordenadas que envía la app del conductor y las
retransmite en tiempo real a los pasajeros por WebSocket.

- **Laravel** 12 · **PHP** 8.2 – 8.5
- **PostgreSQL** + **PostGIS** (base de datos de operaciones **compartida**)
- **Laravel Reverb** (WebSockets) · **Laravel Echo** (cliente, en Flutter)
- Respuestas **JSON:API** (soporte nativo de Laravel 12)

> Estado: **etapa de preparación completa** (Steps 0–13). El flujo HU1+HU2 ya funciona
> end-to-end. Falta implementar formalmente las 3 User Stories y sus tests.

---

## Requisitos en una máquina nueva

### 1. Herramientas base

| Herramienta | Versión | Notas |
|---|---|---|
| **PHP** | 8.2 – 8.5 | Probado en 8.2 (XAMPP) y 8.5 (build oficial de Windows / WinGet). Añade `php` al `PATH`. |
| **Composer** | 2.x | https://getcomposer.org |
| **Git** | cualquiera | |
| Node.js | 18+ | **Opcional.** Solo para el script de verificación WebSocket. El servicio no lo necesita. |

**No** hace falta instalar PostgreSQL ni PostGIS en local: la base de datos de
operaciones es **remota y compartida** (solo necesitas las credenciales).

### 2. Extensiones de PHP

El servicio necesita estas 4 extensiones activas (las DLLs vienen con PHP en Windows;
hay que **descomentarlas** en el `php.ini` — `php --ini` te dice cuál se está cargando):

```ini
extension=pdo_pgsql     ; conexión a PostgreSQL
extension=pgsql         ; conexión a PostgreSQL
extension=intl          ; formateo de números/fechas
extension=sockets       ; servidor Reverb (WebSockets)
```

| Instalación | `php.ini` típico |
|---|---|
| XAMPP | `C:\xampp\php\php.ini` |
| WinGet (`PHP.PHP.8.5`) | `%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.5_*\php.ini` |

Las demás que usa Laravel (`mbstring`, `openssl`, `curl`, `fileinfo`, `tokenizer`,
`ctype`, `json`, `pdo`, `xml`) vienen activas por defecto.

### 3. Verificar

```bash
php -v                                  # entre 8.2 y 8.5
composer -V                             # 2.x
php -m | findstr "pdo_pgsql pgsql intl sockets"   # deben aparecer las 4
```

Si `php -m` no muestra alguna, revisa que editaste el `php.ini` correcto
(`php --ini`) y reinicia la terminal. Si tienes **varios PHP** instalados, confirma
cuál está primero en el `PATH` (`Get-Command php -All`).

Luego seguir con **[Puesta en marcha](#puesta-en-marcha)**.

---

## Dónde encaja

```
Flutter (conductor)  ──HTTP──▶  API Gateway  ──▶  ESTE microservicio  ──▶  PostgreSQL/PostGIS
                                                       │
                                              BusLocationUpdated
                                                       ▼
                                              Laravel Reverb  ──WS──▶  Flutter (pasajero)
```

- **La autenticación NO se hace aquí.** La resuelve el API Gateway y reenvía la
  identidad en cabeceras `X-Auth-*` (ver [docs/GATEWAY.md](docs/GATEWAY.md)).
- Flutter **nunca** llama directo a este servicio, siempre vía Gateway.
- El mapa (tiles OpenStreetMap) lo dibuja Flutter; el routing (OpenRouteService) es
  una integración futura (ver [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md)).

---

## Base de datos — reglas duras

La BD de operaciones **ya existe** y la administra otro equipo.

- ❌ **NO** crear / modificar / ejecutar migraciones. `database/migrations/` está vacío a propósito.
- ❌ **NO** `php artisan migrate`, drop, ni recrear tablas.
- ✅ Solo conectarse y trabajar con las tablas existentes.

Esquema real y convenciones en [docs/DATABASE.md](docs/DATABASE.md).
Detalle importante: `drivers.id` es **UUID** (no bigint); la tabla `users` **no** vive
en esta BD (está en el microservicio de Auth).

---

## Puesta en marcha

> Antes: [Requisitos en una máquina nueva](#requisitos-en-una-máquina-nueva).
> Necesitas también las credenciales de la BD de operaciones.

### Instalación
```bash
composer install
cp .env.example .env
php artisan key:generate
# editar .env: credenciales DB_*  y  credenciales REVERB_*
php artisan reverb:install       # genera REVERB_APP_ID/KEY/SECRET si no los tienes
php artisan config:clear
```

### Verificar la conexión a la BD (sin tocar tablas)
```bash
php artisan db:show
php artisan db:table gps_locations
```

### Correr en local (dos procesos)
```bash
php artisan serve                 # API HTTP   -> http://127.0.0.1:8000
php artisan reverb:start --debug   # WebSocket  -> ws://127.0.0.1:8080
```

---

## API

- **Vía API Gateway** (lo que llama Flutter): `https://…/api/gps/{path}`
- **Ruta interna del microservicio**: `/api/{path}` (`apiPrefix = 'api'`; el Gateway consume el segmento `gps`)

Contrato completo: [docs/openapi.yaml](docs/openapi.yaml) · [docs/GATEWAY.md](docs/GATEWAY.md).

| Método | Vía Gateway | Interna | Descripción |
|---|---|---|---|
| `GET`  | `/api/gps/ping` | `/api/ping` | Healthcheck |
| `POST` | `/api/gps/locations` | `/api/locations` | Registrar una coordenada GPS (HU1) |
| `GET`  | `/api/gps/trips/{tripId}/location` | `/api/trips/{tripId}/location` | Última posición conocida del viaje (HU3) |
| `POST` | `/api/gps/broadcasting/auth` | `/api/broadcasting/auth` | Autorización del canal privado de Reverb |
| `GET`  | — | `/up` | Healthcheck interno de Laravel (no vía Gateway) |

Todas las respuestas y errores siguen **JSON:API** (`application/vnd.api+json`).

**Ejemplo — `POST /api/gps/locations`:**
```json
{
  "data": {
    "type": "gps-locations",
    "attributes": {
      "trip_id": 25,
      "latitude": 10.4631,
      "longitude": -83.9921,
      "speed_kmh": 38.5,
      "recorded_at": "2026-09-03T15:00:00Z"
    }
  }
}
```

---

## Tiempo real

- **Evento:** `BusLocationUpdated` — se dispara al guardar cada coordenada ([docs/EVENTS.md](docs/EVENTS.md)).
- **Canal:** `private-trip.{tripId}` — privado ([docs/CHANNELS.md](docs/CHANNELS.md)).
- **Cliente Flutter:** `echo.private('trip.$id').listen('.BusLocationUpdated', ...)` ([docs/FLUTTER.md](docs/FLUTTER.md)).
- El broadcast es **best-effort**: si Reverb está caído, la coordenada se guarda igual y `POST /api/gps/locations` devuelve `201`.

---

## Estructura del proyecto

```
app/
  Data/GpsFix.php                       DTO inmutable de una lectura validada
  Events/BusLocationUpdated.php         evento de broadcasting (HU2)
  Http/
    Controllers/Api/GpsLocationController.php
    Middleware/
      IdentifyFromGateway.php           reconstruye el usuario desde cabeceras del Gateway
      NegotiatesJsonApi.php             content negotiation JSON:API (415/406)
    Requests/StoreGpsLocationRequest.php
    Resources/
      JsonApi/JsonApiResource.php       base: type en kebab-case plural
      GpsLocationResource.php · TripResource.php
  Models/
    GpsLocation.php · Trip.php · Driver.php (uuid) · Company.php · Route.php · Bus.php
    Concerns/HasLocationPoint.php        deriva la columna PostGIS `location` (lng, lat)
  Services/GpsLocationService.php        punto único donde nace una gps_location
  Support/
    Gateway/GatewayUser.php
    JsonApi/JsonApiErrors.php            documentos de error JSON:API
routes/
  api.php · channels.php
config/
  gateway.php · services.php (ORS/OSM)
docs/                                    ← documentación de arquitectura
```

---

## Documentación

| Archivo | Tema |
|---|---|
| [docs/DATABASE.md](docs/DATABASE.md) | Esquema real de la BD de operaciones |
| [docs/POSTGIS.md](docs/POSTGIS.md) | Columna `location`, regla lng/lat |
| [docs/REVERB.md](docs/REVERB.md) | WebSockets, cómo correrlo |
| [docs/EVENTS.md](docs/EVENTS.md) | `BusLocationUpdated` |
| [docs/CHANNELS.md](docs/CHANNELS.md) | Canal privado + autorización sin auth propia |
| [docs/FLUTTER.md](docs/FLUTTER.md) | Contrato para la app (Echo, payload, reconexión) |
| [docs/GATEWAY.md](docs/GATEWAY.md) | Contrato con el API Gateway |
| [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md) | OpenStreetMap / OpenRouteService (futuro) |
| [docs/openapi.yaml](docs/openapi.yaml) | Especificación OpenAPI 3.1 |

---

## User Stories (pendientes de implementar)

- **HU1** — Recibir coordenadas GPS · `POST /api/gps/locations` funcional; falta endurecer reglas de negocio
- **HU2** — Transmitir por WebSocket · evento + canal + auth listos, flujo verificado end-to-end
- **HU3** — Mapa del pasajero en tiempo real · `GET /api/gps/trips/{tripId}/location` implementado; el resto es cliente Flutter

Todas las **rutas HTTP** que necesitan las 3 HU están implementadas. Falta la lógica
de negocio de cada HU y sus tests.

Los tests de las HU se implementan junto con cada historia (`phpunit.xml` aún apunta a
sqlite `:memory:` — definir la estrategia de BD de test).

---

## Convenciones

- **JSON:API** en todo: nunca respuestas JSON arbitrarias. Errores incluidos.
- **Sin migraciones.** La BD la administra otro equipo.
- **Sin auth propia.** Identidad vía Gateway (`X-Auth-*`).
- PostGIS: el orden es **(longitud, latitud)**. Centralizado en `HasLocationPoint`.
- Verificación de código que escribe en BD: `INSERT` dentro de transacción + `rollBack`.
