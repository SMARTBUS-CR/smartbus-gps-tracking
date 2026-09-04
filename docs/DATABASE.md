# Base de datos de operaciones — esquema existente

> BD compartida `defaultdb` (PostgreSQL 18 + PostGIS, Aiven).
> **Administrada por el equipo de BD. Este microservicio NO crea ni modifica estas tablas.**
> Documento generado por introspección (`php artisan db:table`) el 2026-09-03.

PostGIS confirmado activo (existe `public.spatial_ref_sys`).

## Convención de IDs — IMPORTANTE

| Tabla    | PK      | Tipo          |
|----------|---------|---------------|
| users    | id      | bigint        |
| companies| id      | bigint        |
| buses    | id      | bigint        |
| routes   | id      | bigint        |
| trips    | id      | bigint        |
| gps_locations | id | bigint        |
| **drivers** | **id** | **uuid** ⚠️ |

`drivers` usa **UUID** como PK, y `trips.driver_id` es **uuid**. El resto de FKs son `bigint`.

---

## trips
| Columna       | Tipo                          | Notas |
|---------------|-------------------------------|-------|
| id            | bigint (identity)             | PK |
| route_id      | bigint                        | FK → routes.id (ON DELETE CASCADE) |
| bus_id        | bigint                        | FK → buses.id (ON DELETE CASCADE) |
| driver_id     | uuid                          | FK → drivers.id (ON DELETE CASCADE) |
| status        | varchar(20)                   | default `'scheduled'` — índice |
| started_at    | timestamp(0), null            | |
| completed_at  | timestamp(0), null            | |
| created_at / updated_at | timestamp(0), null  | |

## drivers
| Columna    | Tipo         | Notas |
|------------|--------------|-------|
| id         | uuid         | PK (no autoincrement, no default → lo genera la app que inserta) |
| user_id    | bigint       | UNIQUE. ID opaco del microservicio de Auth (sin FK, sin tabla `users` local) |
| company_id | bigint       | FK → companies.id (ON DELETE CASCADE) |
| license    | varchar(30)  | |
| status     | varchar(20)  | default `'active'` |
| created_at / updated_at | timestamp(0), null | |

## gps_locations
| Columna     | Tipo                     | Notas |
|-------------|--------------------------|-------|
| id          | bigint (identity)        | PK |
| trip_id     | bigint                   | FK → trips.id (ON DELETE CASCADE) — índice |
| latitude    | numeric(10,7)            | NOT NULL |
| longitude   | numeric(10,7)            | NOT NULL |
| speed_kmh   | numeric(8,2)             | NULL |
| recorded_at | timestamp(0) w/o tz      | NOT NULL — índice. Se guarda en UTC |
| location    | geography(Point,4326)    | NULL. Punto = (longitude, latitude) — ver docs/POSTGIS.md |
| created_at / updated_at | timestamp(0), null | |

Índices: `gps_locations_trip_id_index`, `gps_locations_recorded_at_index`.

## buses
`id` bigint · `company_id` bigint (FK companies) · `plate_number` varchar UNIQUE · `unit_number` varchar · `brand?` · `model?` · `year?` smallint · `capacity` smallint default 40 · `is_active` bool default true · timestamps.

## routes
`id` bigint · `company_id` bigint (FK companies) · `code` varchar · `name` varchar · `description?` text · `origin` varchar · `destination` varchar · `distance_km?` numeric(8,2) · `estimated_duration_minutes?` smallint · `is_active` bool default true · timestamps.

## companies / company_user
Existen. `companies.id` es bigint. `company_user` es pivote (bigint↔bigint).

## users — ⚠️ NO EXISTE en esta BD
Introspección 2026-09-03: la BD `defaultdb` **no tiene** tabla `users`
(tablas reales: buses, companies, company_user, drivers, gps_locations, migrations, routes, trips + spatial_ref_sys).

`drivers.user_id` es un `bigint` **sin FK declarada** → es un identificador opaco hacia el
microservicio de **Auth** (que tiene su propia BD). Este microservicio GPS trata `user_id`
como un valor que pasa de largo; NO hace join contra `users`.

El modelo `Driver` **no** define relación `user()`. `user_id` se expone como
atributo entero y se pasa de largo.
