# Integraciones externas — Mapa (OpenFreeMap) y OpenRouteService

> **Estado:**
> - **Mapa: implementado en Flutter** (inicio del pasajero, MapLibre + OpenFreeMap).
> - **OpenRouteService: preparado, NO implementado.** No hay ninguna llamada HTTP real.
>
> Este documento define dónde vive cada integración para que no acabe acoplada al
> `GpsLocationController`.

El microservicio GPS hace **una sola cosa**: recibir coordenadas (HU1) y distribuirlas
por WebSocket (HU2/HU3). El mapa y los cálculos de ruta son otro problema.

```
                       ┌─────────────────────────────────────────┐
   Flutter  ──tiles──▶ │  OpenFreeMap  (tiles vectoriales, OSM)  │
                       └─────────────────────────────────────────┘
                                    ▲
                                    │  el backend NO participa
                                    │
   ┌──────────────┐   coords   ┌───────────────┐   posiciones   ┌───────────┐
   │ Driver app   │ ─────────▶ │  GPS µservice │ ─────WS──────▶ │ Passenger │
   └──────────────┘            └───────┬───────┘                └───────────┘
                                       │ (futuro, detrás de una capa de servicio)
                                       ▼
                       ┌─────────────────────────────────────────┐
                       │  OpenRouteService  (ruta / distancia)   │
                       │  …o el microservicio de ETA en Python    │
                       └─────────────────────────────────────────┘
```

---

## Mapa — OpenFreeMap + MapLibre

**Qué es:** el mapa base (calles, nombres, edificios) sobre el que Flutter dibuja la
posición del usuario y, en próximas historias, rutas, paradas y buses. Los datos son de
OpenStreetMap.

**Proveedor: [OpenFreeMap](https://openfreemap.org)** (tiles **vectoriales** + estilos
MapLibre). Se eligió porque es el único que cumple las dos condiciones del proyecto:

| Requisito | OpenFreeMap |
|---|---|
| Gratis | ✅ Sin costo, **sin API key** ni registro |
| Apto para producción | ✅ **Sin límite** de vistas/peticiones y **uso comercial permitido** |
| Atribución | Obligatoria: `© OpenFreeMap · © OpenMapTiles · Datos de © OpenStreetMap` |
| SLA | ❌ No tiene → la URL es configurable y OpenFreeMap se puede auto-hospedar |

Descartados:
- **`tile.openstreetmap.org`** (tiles OSM estándar): su política de uso no ofrece ninguna
  garantía, puede bloquear apps sin aviso y prohíbe guardar mapas offline.
- **Google Maps**: requiere cuenta de facturación.
- **MapTiler / CARTO / Stadia**: planes gratuitos con límites y condiciones de uso comercial.

**Quién lo usa:** **Flutter**, directamente, con
[`maplibre_gl`](https://pub.dev/packages/maplibre_gl) (librería oficial de MapLibre,
render nativo). El backend GPS **no** llama al proveedor ni proxya tiles.

| Modo | Proveedor | URL |
|---|---|---|
| Calles (claro y oscuro) | OpenFreeMap, estilo Liberty | `https://tiles.openfreemap.org/styles/liberty` |
| Satélite | Esri World Imagery (⚠️ solo desarrollo / demo) | `https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}` |

- En **modo oscuro** se mantiene el mapa claro: el estilo "Dark" de OpenFreeMap no trae
  lugares ni negocios.
- **Satélite — pendiente para producción:** Esri da alta resolución (casas, calles; zoom 19)
  y su endpoint público funciona sin API key, pero está bajo el *Esri Master License
  Agreement*, que exige una cuenta de ArcGIS para apps en producción. Antes de producción:
  - crear una cuenta (plan gratis) de ArcGIS Location Platform y usar su URL con token, **o**
  - pasar a **EOX Sentinel-2 cloudless 2016** (gratis, sin key, CC BY 4.0, uso comercial
    permitido), de menor resolución (útil hasta zoom ~14: barrios, no casas):
    `https://tiles.maps.eox.at/wmts/1.0.0/s2cloudless_3857/default/g/{z}/{y}/{x}.jpg`.
    Solo la capa 2016 sirve (2018+ son CC BY-NC-SA; 2017 no tiene imagen de Costa Rica a
    zoom alto).

Las URLs se cambian sin recompilar código con `--dart-define=MAP_STYLE_URL=…` /
`MAP_SATELLITE_TILES_URL=…` (repo Flutter: `lib/core/config/map_config.dart`, documentado
en `lib/README.md`, sección 6).

**Config del backend:** el bloque `services.openstreetmap` (`OSM_TILE_URL`,
`OSM_ATTRIBUTION`) quedó de la etapa de preparación y **Flutter no lo usa**. Si algún día
se quiere que el backend decida el estilo del mapa, sería un endpoint trivial de config
que devuelva la URL del estilo (fuera del alcance actual).

---

## OpenRouteService (ORS)

**Qué es:** API de routing — distancia/tiempo por carretera, geometría de la ruta,
matrices origen-destino, *map matching* (pegar un punto GPS a la calle).

**Para qué se usará (futuro):**
- distancia recorrida / restante sobre la ruta del viaje,
- descartar lecturas GPS imposibles (saltos),
- alimentar el **microservicio de ETA (Python/Scikit-Learn)**.

### Dónde vive — la regla

`GpsLocationController` y `GpsLocationService::record()` **nunca** llaman a ORS.
La ingesta (HU1) debe ser rápida y no depender de un tercero.

Cuando se implemente, el patrón es:

```
app/
  Contracts/
    RoutingProvider.php          ← interfaz (distanceBetween, routeGeometry, snapToRoute…)
  Integrations/
    OpenRouteService/
      OpenRouteServiceClient.php  ← implements RoutingProvider (HTTP a ORS, timeouts, cache)
  Services/
    RouteMatchingService.php      ← lógica de negocio; depende de RoutingProvider, no de ORS
```

- El binding `RoutingProvider → OpenRouteServiceClient` se registra en un provider.
- Los consumidores piden `RoutingProvider` por constructor.
- Ventajas: se testea con un fake, se cambia de proveedor sin tocar negocio, y el
  cálculo puede **moverse al microservicio de ETA** sin reescribir el GPS service.

### Config (ya preparada)

```php
config('services.openrouteservice.base_url')   // https://api.openrouteservice.org
config('services.openrouteservice.api_key')    // ORS_API_KEY  (vacío por ahora)
config('services.openrouteservice.profile')    // driving-hgv (un bus ≈ vehículo pesado)
config('services.openrouteservice.timeout')    // 5 s
```

`.env`: `ORS_BASE_URL`, `ORS_API_KEY`, `ORS_PROFILE`, `ORS_TIMEOUT`.

### Consideraciones para cuando se implemente
- **Rate limits**: el plan gratuito de ORS es limitado → cachear resultados (la
  geometría de una ruta no cambia) y/o self-host de ORS.
- **Timeouts cortos + circuit breaker**: ORS caído no puede degradar la ingesta.
- Trabajo pesado → cola, no en el request.

---

## Resumen de responsabilidades

| | Mapa (OpenFreeMap) | OpenRouteService |
|---|---|---|
| Lo llama | Flutter (`maplibre_gl`) | Backend (capa de servicio) / ETA µservice |
| El GPS controller lo toca | nunca | nunca |
| Estado hoy | **implementado** (inicio del pasajero) | solo config |
| Acoplamiento previsto | ninguno (Flutter) | detrás de `RoutingProvider` |
