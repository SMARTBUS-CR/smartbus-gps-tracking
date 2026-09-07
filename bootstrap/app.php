<?php

use App\Http\Middleware\IdentifyFromGateway;
use App\Http\Middleware\NegotiatesJsonApi;
use App\Support\JsonApi\JsonApiErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/*
| Prefijo de las rutas API = 'api' (default de Laravel 12).
|
| El API Gateway (base /api, ruta /api/{service}/{path}) consume el segmento
| {service} para elegir destino y reenvia  /api/{path}  al microservicio:
|
|     Flutter  ->  <gateway>/api/gps/locations
|                        |  proxyTo(request, Services::GPS, 'locations')
|                        v
|     GPS      ->  /api/locations
|
| Por eso aqui las rutas viven en /api/*  (no /gps/*). Ver docs/GATEWAY.md.
*/
$apiPrefix = 'api';

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: $apiPrefix,
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
    | Autorizacion de canales (routes/channels.php) + ruta /api/broadcasting/auth
    | (via Gateway: /api/gps/broadcasting/auth).
    | Middleware propio: solo IdentifyFromGateway (NO el grupo "api": la respuesta
    | de auth de Pusher no es JSON:API y no debe pasar por NegotiatesJsonApi).
    */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: [
            'prefix' => $apiPrefix,
            'middleware' => [IdentifyFromGateway::class],
        ],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // El microservicio corre DETRAS del API Gateway (red privada, no expuesta).
        // Se confia en sus X-Forwarded-* para ver scheme/host/IP reales del cliente.
        // TODO produccion: restringir 'at' al CIDR del Gateway en vez de '*'.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Todas las rutas del grupo "api" hacen content negotiation JSON:API
        // y reconstruyen la identidad reenviada por el API Gateway.
        $middleware->api(append: [
            IdentifyFromGateway::class,
            NegotiatesJsonApi::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($apiPrefix): void {
        // Cualquier error en una ruta del microservicio se serializa como
        // documento de error JSON:API (nunca HTML, nunca JSON arbitrario).
        $exceptions->render(function (\Throwable $e, Request $request) use ($apiPrefix) {
            if ($request->is($apiPrefix.'/*') || $request->expectsJson()) {
                return JsonApiErrors::fromThrowable($e, config('app.debug'));
            }

            return null;
        });
    })->create();
