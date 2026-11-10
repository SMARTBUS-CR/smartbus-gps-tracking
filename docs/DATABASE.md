# Base de datos de operaciones — esquema que usa GPS

> BD compartida `defaultdb` (PostgreSQL + PostGIS, Aiven).
> **Las tablas las define el Dashboard** (`smartbus-dashboard-admin`, `database/migrations`,
> rama `dev`, migraciones del 2026-10-13). **Este microservicio no crea ni migra tablas.**
> Para los tests hay una copia del esquema solo de prueba en
> [`database/migrations-testing/`](../database/migrations-testing) (nunca se corre contra Aiven).

## Qué hace GPS con cada tabla

| Tabla | GPS | Para qué |
|---|---|---|
| `trips` | lee | Saber si el viaje está en curso y quién es su conductor (lo inicia / termina el Dashboard) |
| `drivers` | lee | Saber si el usuario del token es el conductor asignado |
| `gps_locations` | **escribe** | Muestras históricas de posición |
| `companies`, `routes`, `route_patterns`, `route_schedules`, `buses` | no (solo claves foráneas de `trips`) | — |

## Convención de IDs

Todas las PK y FK son `uuid` (UUIDv7) sin DEFAULT en la BD: el UUID lo genera quien inserta.
GPS solo inserta en `gps_locations` (`HasUuids`). Cualquier id que llegue de fuera se valida
como UUID **antes** de consultar (Postgres lanza `invalid input syntax for type uuid`).

---

## trips
| Columna | Tipo | Notas |
|---|---|---|
| id | uuid | PK |
| company_id | uuid | FK companies |
| route_pattern_id | uuid | FK route_patterns (dirección / variante de la ruta) |
| route_schedule_id | uuid | FK route_schedules |
| bus_id, driver_id | uuid | FK compuestas con `company_id`: bus y conductor **de la misma compañía** |
| service_date | date | |
| status | varchar(20) | `scheduled` · `in_progress` · `completed` · `cancelled` |
| scheduled_departure_at / scheduled_arrival_at | timestamptz(6) | Planificado por el Dashboard |
| actual_departure_at / actual_arrival_at | timestamptz(6) null | Los escribe el Dashboard al iniciar / terminar |
| planned_route_snapshot | jsonb | Copia del recorrido (no se usa todavía) |
| cancellation_reason, cancelled_at | | Los escribe el Dashboard |

**`trips_execution_check`** (la BD lo exige):

| status | Requiere |
|---|---|
| `scheduled` | sin `actual_*` ni datos de cancelación |
| `in_progress` | `actual_departure_at` |
| `completed` | `actual_departure_at` + `actual_arrival_at` (llegada ≥ salida) |
| `cancelled` | `cancelled_at` + `cancellation_reason` |

Las transiciones las hace el Dashboard (`POST /api/v1/operations/trips/{id}/start | complete`).
GPS solo acepta coordenadas del conductor asignado mientras el viaje está `in_progress`.

## drivers
| Columna | Tipo | Notas |
|---|---|---|
| id | uuid | PK |
| company_id | uuid | FK companies |
| user_id | uuid | Usuario del microservicio de Auth. **Único por compañía**: una persona puede ser conductora en varias compañías |
| license, license_expires_at, employment_starts_on, employment_ends_on | | |
| status | varchar(20) | `active` · `inactive` |
| deleted_at | | soft delete |

"Es el conductor del viaje" = `drivers.id = trips.driver_id` **y** `drivers.user_id` = usuario del
token **y** `status = active` (sin borrar). Se parte siempre del viaje, nunca del usuario.

## gps_locations
| Columna | Tipo | Notas |
|---|---|---|
| id | uuid | PK, `HasUuids` |
| trip_id | uuid | FK trips (`restrictOnDelete`) |
| latitude, longitude | numeric(10,7) | Con CHECK de rango |
| speed_kmh | numeric(8,2) null | 0–400 |
| accuracy_meters | numeric(10,2) null | Precisión del dispositivo |
| recorded_at | timestamptz(6) | Cuándo tomó la lectura el teléfono |
| received_at | timestamptz(6) | Cuándo la recibió GPS (default `now()`) |
| location | geography(Point,4326) null | Derivado de lat/lng — ver [POSTGIS.md](POSTGIS.md) |

**`UNIQUE (trip_id, recorded_at)`**: una lectura repetida no se puede guardar dos veces.

## users — no existe en esta BD
Vive en el microservicio de Auth. `drivers.user_id` es un identificador opaco hacia ese servicio.
