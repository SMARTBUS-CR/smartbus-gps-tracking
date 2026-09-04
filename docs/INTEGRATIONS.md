# Integraciones externas — OpenStreetMap y OpenRouteService

> **Estado: preparado, NO implementado.** Este documento define dónde vivirán para
> que no acaben acopladas al `GpsLocationController`. No hay ninguna llamada HTTP real.

El microservicio GPS hace **una sola cosa**: recibir coordenadas (HU1) y distribuirlas
por WebSocket (HU2/HU3). El mapa y los cálculos de ruta son otro problema.

```
                       ┌─────────────────────────────────────────┐
   Flutter  ──tiles──▶ │  OpenStreetMap  (tile server)           │
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

## OpenStreetMap

**Qué es:** proveedor de *tiles* (las imágenes del mapa).

**Quién lo usa:** **Flutter**, directamente (`flutter_map` / `TileLayer` apuntando a
`OSM_TILE_URL`). El backend GPS **no** llama a OSM ni proxya tiles.

**Qué hace el backend:** solo guardar la URL y la atribución en config, por si algún
día se cambia de proveedor (MapTiler, Stadia, un tile server propio…) sin recompilar
la app:

```php
config('services.openstreetmap.tile_url')      // https://tile.openstreetmap.org/{z}/{x}/{y}.png
config('services.openstreetmap.attribution')
```

Si más adelante se quiere exponer esto a Flutter, sería un endpoint trivial de
config (fuera del alcance de esta etapa).

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

| | OpenStreetMap | OpenRouteService |
|---|---|---|
| Lo llama | Flutter | Backend (capa de servicio) / ETA µservice |
| El GPS controller lo toca | nunca | nunca |
| Estado hoy | solo config | solo config |
| Acoplamiento previsto | ninguno (Flutter) | detrás de `RoutingProvider` |
