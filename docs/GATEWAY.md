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

**Ninguna propia.** El Gateway valida el token con el Authentication Service y
reenvía **la misma** cabecera `Authorization` al microservicio (no inyecta `X-User-*`):

```
Flutter ──Authorization: Bearer <token>──▶ Gateway ──valida con Auth──▶ GPS µservice
                                                                        │
                                   (solo si la ruta necesita el usuario) ▼
                                          Auth  GET /api/user?include=roles
```

- **Todas** las rutas `/api/gps/*` requieren token válido en el Gateway.
- El GPS service resuelve **quién** es el usuario con `IdentifyFromGateway`: manda el
  mismo Bearer token a Auth `GET /api/user?include=roles` y arma un `GatewayUser { id, roles }`.
  - Es **perezoso**: solo llama a Auth cuando algo pide `$request->user()` (hoy, la
    autorización del canal en `/api/broadcasting/auth`). `POST /api/locations` no llama a Auth.
  - Se **cachea** por token (`AUTH_SERVICE_CACHE_TTL`, 300 s por defecto). Los errores no se cachean.
  - Cualquier `X-User-*` que venga del cliente se **ignora**: la identidad no se puede falsificar.

Configuración en [`config/gateway.php`](../config/gateway.php) (env `AUTH_SERVICE_URL`).

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

El proxy de Laravel del Gateway (`proxyTo`, Http::) es HTTP y **no** hace el *upgrade*
a WebSocket. Por eso el Gateway tiene un **nginx delante** (`docker/nginx.conf.template`)
que manda `/app/*` (y `/api/gps/app/*`) directo al nginx del GPS service, que a su vez
lo pasa a Reverb (`location /app` → `127.0.0.1:8080`). Todo lo demás (`/api/gps/*` REST
y `broadcasting/auth`) pasa por Laravel, que valida el token.

```
Pasajero Flutter
   │
   ├── wss://<gateway>/app/<REVERB_APP_KEY>          ← WS: nginx del Gateway → Reverb
   │   (o directo a wss://<host-gps>/app/<KEY>)
   │
   └── POST https://<gateway>/api/gps/broadcasting/auth  ← auth del canal: Laravel del Gateway
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

## 5. Qué necesita el Gateway — checklist

1. **Registrar `gps`** en `Services::GPS`, apuntando a la URL del microservicio (dev y prod).
2. Rutear `/api/gps/{path}` con `proxyTo($request, Services::GPS->value, $path)` — el
   microservicio recibe `/api/{path}` (ej. `/api/gps/locations` → `/api/locations`).
   El nginx del Gateway **no** debe interceptar `/api/gps/*` REST: si lo hace, se salta
   la validación del token.
3. Aplicar a `/api/gps/*` el middleware `validate.token` y **reenviar la cabecera
   `Authorization`** tal cual (el GPS service la usa para resolver al usuario).
4. Los IDs (`tripId`, etc.) son **UUID**: nada de `whereNumber`.
5. Reenviar `socket_id` y `channel_name` de `broadcasting/auth`. Echo/Pusher los manda
   como `application/x-www-form-urlencoded`; el Gateway los convierte a JSON en
   `GPSTrackingController::authenticateBroadcast` (este servicio acepta ambos formatos).

Del lado del microservicio ya está: `apiPrefix = 'api'` en
[`bootstrap/app.php`](../bootstrap/app.php) → rutas en `/api/*`.

---

## 6. Resumen para el equipo del Gateway

| | |
|---|---|
| Servicio | **`gps`** → URL del GPS microservice en Render |
| Rutas | `POST /api/gps/locations`, `GET /api/gps/trips/{tripId}/location`, `GET\|POST /api/gps/broadcasting/auth` |
| El microservicio recibe | `/api/{path}` (el Gateway quita `gps`) |
| Auth | `validate.token` en el Gateway + reenviar `Authorization: Bearer` tal cual |
| IDs | UUID |
| WebSocket | nginx del Gateway `/app/*` → GPS → Reverb (§4) |
