# El microservicio GPS detrás del API Gateway

Flutter **nunca** llama directo a este servicio. Habla con el **API Gateway**, que
valida el token y hace de proxy con patrón `/{service}/{path}`.

Este servicio **no** implementa otro gateway. Solo se configura para vivir detrás de uno.

```
Flutter ──▶ API Gateway ──(red privada)──▶ GPS microservice
             (auth, TLS, rate limit)         (/gps/*)
```

## Lo que el Gateway DEBE hacer en cada request hacia /gps/*

### 1. Autenticación
- Validar el token del usuario. El GPS service **nunca** ve el token.
- Rechazar (401) antes de reenviar si el token es inválido.

### 2. Inyectar cabeceras de identidad
| Cabecera | Obligatoria | Contenido |
|---|---|---|
| `X-Auth-User-Id` | sí | id numérico del usuario |
| `X-Auth-Roles` | no | roles separados por coma (`passenger,driver`) |
| `X-Auth-Company-Id` | no | id de la compañía |

Nombres configurables en [`config/gateway.php`](../config/gateway.php) (`GATEWAY_HEADER_*`).
Las lee [`IdentifyFromGateway`](../app/Http/Middleware/IdentifyFromGateway.php).

### 3. ⚠️ Eliminar las cabeceras `X-Auth-*` que mande el cliente
El GPS service **confía ciegamente** en `X-Auth-*`. Si un cliente pudiera enviarlas
y el Gateway no las stripeara, cualquiera se haría pasar por otro usuario.
**Verificado:** una request directa con `X-Auth-User-Id: 999` es tomada como usuario 999.
Por eso: red Gateway↔GPS privada + Gateway que sanea la entrada.

### 4. Reenviar `X-Forwarded-*`
`X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host`, `X-Forwarded-Port`.
El servicio confía en ellas ([`trustProxies(at: '*')`](../bootstrap/app.php)) para
ver el scheme/host/IP reales del cliente. **Verificado:** con `X-Forwarded-Proto: https`
el servicio reporta `secure=true` y `ip` = IP real del cliente.
> Producción: cambiar `at: '*'` por el CIDR del Gateway.

### 5. Rate limiting
Lo hace el **Gateway** (todas las requests le llegan con la misma IP; limitar aquí
por IP no serviría). El servicio hoy **no** limita. Si más adelante se quiere una
red de seguridad a nivel servicio, se define un `RateLimiter::for('api', ...)`
keyeado por `X-Auth-User-Id`.

## Rutas del servicio

| Ruta | Tipo | Notas |
|---|---|---|
| `POST /gps/locations` | pública vía Gateway | ingesta de coordenadas (HU1) |
| `GET /gps/ping` | healthcheck vía Gateway | `{"service":"smartbus-gps","status":"ok"}` |
| `POST /gps/broadcasting/auth` | vía Gateway | autoriza canal privado de Reverb (formato Pusher, no JSON:API) |
| `GET /up` | **interno** | healthcheck de Laravel; en la raíz, el Gateway no lo alcanza → usarlo solo en checks directos (k8s/LB) |

## WebSocket (Reverb)

El canal privado se autoriza vía HTTP normal (`POST /gps/broadcasting/auth`, pasa por
el Gateway como cualquier ruta). La **conexión WebSocket** en sí (`wss://.../app/{key}`)
la sirve el proceso Reverb: el Gateway puede proxearla también, o exponerse Reverb
por su propio host/puerto. A definir con infra. Ver [docs/REVERB.md](REVERB.md) y
[docs/CHANNELS.md](CHANNELS.md).

## ⛔ Puntos abiertos (pendientes del equipo del Gateway)

1. **Reenvío del path.** El Gateway usa `/{service}/{path}`. ¿Reenvía…
   - **(A)** la ruta completa → `GET http://gps-service/gps/locations` → encaja con
     `apiPrefix = 'gps'` actual. *(asumido)*
   - **(B)** solo el path → `GET http://gps-service/locations` → poner `apiPrefix = ''`
     en [`bootstrap/app.php`](../bootstrap/app.php) (one-liner) y `routes/channels.php`
     queda sin el prefijo `gps`.

   Mientras no se defina, seguimos con **(A)**.

2. **Exposición de Reverb** (proxy del WS por el Gateway vs. host propio).

3. **CIDR del Gateway** para acotar `trustProxies`.
