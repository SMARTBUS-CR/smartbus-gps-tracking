# Base de datos de operaciones — esquema existente

> BD compartida `defaultdb` (PostgreSQL 18 + PostGIS, Aiven).
> **Administrada por el equipo de BD. Este microservicio NO crea ni modifica estas tablas.**
> Documento generado por introspección (`php artisan db:table`) el 2026-09-03,
> actualizado el 2026-09-24 tras la migración del equipo de BD a UUID.

PostGIS confirmado activo (existe `public.spatial_ref_sys`).

## Convención de IDs — IMPORTANTE

**Todas las PK y FK son `uuid`** (companies, buses, routes, drivers, trips,
gps_locations, y los `user_id` que apuntan al microservicio de Auth). Única
excepción: `company_user.id` sigue siendo `bigint` autoincremental.

Ninguna PK uuid tiene DEFAULT en la BD: el UUID lo genera la aplicación que
inserta. Los existentes son **UUIDv7** (ordenables por tiempo), el mismo formato
que genera `HasUuids` de Laravel 12. En este microservicio solo `GpsLocation`
inserta filas, y usa `HasUuids`; el resto de modelos declaran
`$keyType = 'string'` y `$incrementing = false`.

Cualquier id que llegue de fuera (request, ruta, canal, headers del Gateway) se
valida como UUID **antes** de consultar: Postgres lanza
`invalid input syntax for type uuid` si recibe, p. ej., `1`.

---

## trips
| Columna       | Tipo                          | Notas |
|---------------|-------------------------------|-------|
| id            | uuid                          | PK |
| route_id      | uuid                          | FK → routes.id (ON DELETE CASCADE) |
| bus_id        | uuid                          | FK → buses.id (ON DELETE CASCADE) |
| driver_id     | uuid                          | FK → drivers.id (ON DELETE CASCADE) |
| status        | varchar(20)                   | default `'scheduled'` — índice |
| started_at    | timestamp(0), null            | |
| completed_at  | timestamp(0), null            | |
| created_at / updated_at | timestamp(0), null  | |

## drivers
| Columna    | Tipo         | Notas |
|------------|--------------|-------|
| id         | uuid         | PK (no autoincrement, no default → lo genera la app que inserta) |
| user_id    | uuid         | UNIQUE. ID opaco del microservicio de Auth (sin FK, sin tabla `users` local) |
| company_id | uuid         | FK → companies.id (ON DELETE CASCADE) |
| license    | varchar(30)  | |
| status     | varchar(20)  | default `'active'` |
| created_at / updated_at | timestamp(0), null | |

## gps_locations
| Columna     | Tipo                     | Notas |
|-------------|--------------------------|-------|
| id          | uuid                     | PK. Sin default → lo genera `GpsLocation` (`HasUuids`, UUIDv7) |
| trip_id     | uuid                     | FK → trips.id (ON DELETE CASCADE) — índice |
| latitude    | numeric(10,7)            | NOT NULL |
| longitude   | numeric(10,7)            | NOT NULL |
| speed_kmh   | numeric(8,2)             | NULL |
| recorded_at | timestamp(0) w/o tz      | NOT NULL — índice. Se guarda en UTC |
| location    | geography(Point,4326)    | NULL. Punto = (longitude, latitude) — ver docs/POSTGIS.md |
| created_at / updated_at | timestamp(0), null | |

Índices: `gps_locations_trip_id_index`, `gps_locations_recorded_at_index`.

## buses
`id` uuid · `company_id` uuid (FK companies) · `plate_number` varchar UNIQUE · `unit_number` varchar · `brand?` · `model?` · `year?` smallint · `capacity` smallint default 40 · `is_active` bool default true · timestamps.

## routes
`id` uuid · `company_id` uuid (FK companies) · `code` varchar · `name` varchar · `description?` text · `origin` varchar · `destination` varchar · `distance_km?` numeric(8,2) · `estimated_duration_minutes?` smallint · `is_active` bool default true · timestamps.

## companies / company_user
Existen. `companies.id` es uuid. `company_user` es pivote (`id` bigint autoincremental; `company_id` y `user_id` uuid).

## users — ⚠️ NO EXISTE en esta BD
Introspección 2026-09-03: la BD `defaultdb` **no tiene** tabla `users`
(tablas reales: buses, companies, company_user, drivers, gps_locations, migrations, routes, trips + spatial_ref_sys).

`drivers.user_id` es un `uuid` **sin FK declarada** → es un identificador opaco hacia el
microservicio de **Auth** (que tiene su propia BD). Este microservicio GPS trata `user_id`
como un valor que pasa de largo; NO hace join contra `users`.

El modelo `Driver` **no** define relación `user()`. `user_id` se expone como
atributo (string uuid) y se pasa de largo.
