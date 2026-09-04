# PostGIS en el microservicio GPS

BD: PostgreSQL 18 + **PostGIS 3.6** (`select postgis_version()`), extensión ya instalada
(existe `public.spatial_ref_sys`). Este servicio **no** crea ni altera columnas geográficas.

## La columna `gps_locations.location`

Tipo real: `geography(Point, 4326)` — un punto en coordenadas WGS84 (grados lat/lng),
con cálculos de distancia en **metros** sobre el elipsoide.

`gps_locations` guarda la posición **por partida doble**:

| Columna | Tipo | Rol |
|---|---|---|
| `latitude`, `longitude` | `numeric(10,7)` | **Fuente de verdad** para mostrar / transmitir. Lo que llega en el request. |
| `location` | `geography(Point,4326)` | **Derivado.** Solo para consultas espaciales: distancia recorrida, `ST_DWithin`, snapping a ruta, ETA. |

## La regla que rompe a todo el mundo: orden lng/lat

Un `Point` en PostGIS es **(X, Y) = (longitud, latitud)**, en ese orden.
GeoJSON usa el mismo orden: `"coordinates": [lng, lat]`.
Pero los humanos decimos "lat, long". Invertirlos manda el bus a otro continente.

En este proyecto el orden vive en **un solo sitio**:
[`app/Models/Concerns/HasLocationPoint.php`](../app/Models/Concerns/HasLocationPoint.php).

```php
// dentro del trait, al guardar cualquier modelo con lat/lng + location:
$this->location = DB::raw(
    "ST_SetSRID(ST_MakePoint({$lng}, {$lat}), 4326)::geography"
    //                        ^^^^^ longitud primero  ^^^^^ latitud después
);
```

- `ST_MakePoint($lng, $lat)` → construye el punto (geometry).
- `ST_SetSRID(..., 4326)` → le fija el sistema de referencia WGS84.
- `::geography` → castea al tipo de la columna.
- `$lng`/`$lat` se formatean con `sprintf('%.8F', (float) $v)`: literal numérico,
  sin locale (nunca coma decimal) y sin inyección SQL.

El trait engancha el evento `saving` de Eloquent, así que **cualquier** `create()`/`save()`
de `GpsLocation` sincroniza `location` automáticamente. El request nunca toca `location`
(no está en `$fillable`).

## Cómo manejarlo desde PHP / Laravel

| Necesito... | Cómo |
|---|---|
| **Escribir** la posición | `GpsLocation::create(['latitude' => .., 'longitude' => .., ...])` — `location` se deriva sola |
| **Mostrar** la posición | `$loc->latitude`, `$loc->longitude` (cast `float`) o `$loc->coordinates` → `[lng, lat]` |
| **Leer** `location` como GeoJSON | `GpsLocation::withLocationGeoJson()->find($id)->location_geojson` |
| **Distancia** entre dos lecturas (metros) | `ST_Distance(a.location, b.location)` en un `selectRaw`/query builder |
| Evitar fugas del binario EWKB | `location` está en `$hidden`; solo sale vía el scope explícito |

## Lo que NO hacemos

- No `ALTER TABLE`, no `CREATE EXTENSION`, no índices — eso es del equipo de BD.
- No instalamos paquetes espaciales (`laravel-magellan`, `eloquent-spatial`): con una
  expresión SQL y un trait de ~40 líneas basta para lo que este servicio necesita.
- No leemos `location` como tipo nativo: para lógica espacial se usa SQL (`ST_*`) en la
  capa de servicio/consulta, no el atributo del modelo.
