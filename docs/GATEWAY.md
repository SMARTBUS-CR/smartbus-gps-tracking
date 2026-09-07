# El microservicio GPS detrás del API Gateway

Flutter **nunca** llama directo a este servicio. Habla con el **API Gateway**
(Laravel, en Render), que valida el token y hace de reverse-proxy.

```
                        https://smartbus-api-gateway.onrender.com/api        (prod)
                        https://smartbus-api-gateway-dev.onrender.com/api    (dev)

Flutter ──▶ API Gateway ──┬── Authentication Service
                          ├── (otros microservicios)
                          └── GPS microservice   ← agregar
```

El Gateway ya tiene una ruta dinámica `ANY /api/{service}/{path}` que consume el
segmento `{service}` para elegir destino y reenvía `/api/{path}` al microservicio:

```
Flutter  ──▶  <gateway>/api/gps/locations
                   │   proxyTo($request, Services::GPS, 'locations')
                   ▼
GPS      ──▶  /api/locations
```

Por eso el microservicio GPS tiene sus rutas en **`/api/*`** (`apiPrefix = 'api'`,
el default de Laravel), **no** en `/gps/*`.

Este servicio **no** implementa otro gateway. Solo se configura para vivir detrás de uno.

---

## 1. ¿Qué endpoints tiene el GPS microservice?

| Método | Vía Gateway (lo que llama Flutter) | Ruta interna | Quién lo usa |
|---|---|---|---|
| `POST` | `/api/gps/locations` | `/api/locations` | app del **conductor** — envía coordenadas (HU1) |
| `GET`  | `/api/gps/trips/{tripId}/location` | `/api/trips/{tripId}/location` | app del **pasajero** — posición actual al abrir el mapa (HU3) |
| `POST` | `/api/gps/broadcasting/auth` | `/api/broadcasting/auth` | app del **pasajero** (Laravel Echo) — autoriza el canal privado |
| `GET`  | `/api/gps/ping` | `/api/ping` | healthcheck |
| `GET`  | *(no exponer)* | `/up` | healthcheck interno de Laravel (Render/orquestador, acceso directo) |

Todas las respuestas y errores son **JSON:API** (`application/vnd.api+json`), salvo
`/api/gps/broadcasting/auth` que responde el formato de Pusher (`{"auth":"..."}`).

---

## 2. ¿Qué autenticación necesita?

**Ninguna propia.** Exactamente el mismo modelo que el resto de microservicios:

```
Flutter ──Authorization: Bearer <token>──▶ Gateway ──valida──▶ GPS µservice
                                              │
                                              └── reenvía  X-User-Id
                                                           X-User-Roles
```

- **Todas** las rutas `/api/gps/*` requieren token válido. No hay "excepción de login"
  aquí (el GPS service no tiene endpoints públicos). `/api/gps/ping` puede quedar sin
  token si el Gateway lo necesita para health checks.
- El GPS service **confía ciegamente** en `X-User-Id` / `X-User-Roles`.
  → El Gateway **debe eliminar** cualquier `X-User-*` que venga del cliente.
  **Verificado:** una request directa con `X-User-Id: 999` es tomada como usuario 999.
  Por eso: red Gateway↔GPS privada + Gateway que sanea la entrada.
- No se usa `X-User-Id` para autorizar todavía (eso llega con HU1: verificar que el
  conductor es dueño del viaje). Por ahora solo hace falta que **llegue**.

Nombres de cabecera configurables en [`config/gateway.php`](../config/gateway.php)
(env `GATEWAY_HEADER_USER_ID`, `GATEWAY_HEADER_ROLES`). Default = `X-User-Id` / `X-User-Roles`,
que es lo que ya envía el Gateway.

---

## 3. ¿Qué rutas necesita Flutter (a través del Gateway)?

Base: `https://smartbus-api-gateway.onrender.com/api`

| App | Llamada |
|---|---|
| Conductor | `POST /api/gps/locations` con `Authorization: Bearer <token>` |
| Pasajero | `GET /api/gps/trips/{tripId}/location` (posición actual al abrir el mapa) |
| Pasajero | `POST /api/gps/broadcasting/auth` (lo hace Laravel Echo solo, al suscribirse) |

---

## 4. ¿Qué necesita Reverb (WebSockets)?

⚠️ **La conexión WebSocket NO pasa por el Gateway.**
El proxy `ANY /{service}/{path}` es HTTP (Guzzle/Http::) y **no** hace el *upgrade*
a WebSocket. Reverb tiene que exponerse por su **propio host**.

```
Pasajero Flutter
   │
   ├── wss://<host-de-reverb>/app/<REVERB_APP_KEY>      ← conexión WS: DIRECTA a Reverb
   │       (Reverb como su propio servicio en Render, puerto 443, TLS)
   │
   └── POST https://.../api/gps/broadcasting/auth        ← auth del canal: vía Gateway
```

**Para desplegar Reverb en Render:** un servicio aparte (o el mismo servicio corriendo
`php artisan reverb:start` además del web), con su host/subdominio propio, p. ej.
`smartbus-gps-ws.onrender.com`. Ver [docs/REVERB.md](REVERB.md).

**Config de Laravel Echo en Flutter** (ver [docs/FLUTTER.md](FLUTTER.md)):
```dart
key:          '<REVERB_APP_KEY>'                        // no es secreto
wsHost:       'smartbus-gps-ws.onrender.com'            // host propio de Reverb
wsPort:       443
forceTLS:     true
authEndpoint: 'https://smartbus-api-gateway.onrender.com/api/gps/broadcasting/auth'
authHeaders:  { 'Authorization': 'Bearer <token>' }     // lo consume el Gateway
```

---

## 5. Qué agregar al Gateway — checklist

1. **Registrar `gps`** en el enum/registro de servicios del proxy (`Services::GPS`),
   apuntando a la URL del microservicio GPS en Render (dev y prod).
2. Rutear `/api/gps/{path}` con `proxyTo($request, Services::GPS->value, $path)` — el
   microservicio recibe `/api/{path}` (ej. `/api/gps/locations` → `/api/locations`).
3. Aplicar a `/api/gps/*` el **mismo middleware de auth** que a los servicios protegidos
   (validar `Bearer`, reenviar `X-User-Id` / `X-User-Roles`).
4. **Stripear** `X-User-*` entrantes del cliente antes de reenviar.
5. Reenviar `X-Forwarded-For` / `-Proto` / `-Host` (Render normalmente ya lo hace).
6. **No** intentar proxiar el WebSocket. Reverb va por su propio host; Flutter apunta
   ahí directo para `wss://` (§4).

Del lado del microservicio ya está: `apiPrefix = 'api'` en
[`bootstrap/app.php`](../bootstrap/app.php) → rutas en `/api/*`.

---

## 6. Resumen para el equipo del Gateway

| | |
|---|---|
| Servicio | **`gps`** → URL del GPS microservice en Render |
| Rutas | `POST /api/gps/locations`, `GET /api/gps/trips/{tripId}/location`, `POST /api/gps/broadcasting/auth`, `GET /api/gps/ping` |
| El microservicio recibe | `/api/{path}` (el Gateway quita `gps`) |
| Auth | igual que los demás: `Bearer` → `X-User-Id` + `X-User-Roles`; stripear `X-User-*` del cliente |
| WebSocket | **fuera del Gateway** — Reverb con host propio (§4) |
