<?php

use App\Http\Middleware\IdentifyFromGateway;
use App\Http\Middleware\NegotiatesJsonApi;
use App\Support\JsonApi\JsonApiErrors;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/*
| API route prefix = 'api' (Laravel's default).
|
| The API Gateway (base /api, route /api/{service}/{path}) consumes the
| {service} segment to pick the target and forwards /api/{path} to the service:
|
|     Flutter  ->  <gateway>/api/gps/locations
|                        |  proxyTo(request, Services::GPS, 'locations')
|                        v
|     GPS      ->  /api/locations
|
| That is why routes here live under /api/* (not /gps/*). See docs/GATEWAY.md.
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
    | Channel authorization (routes/channels.php) + the /api/broadcasting/auth
    | route (via Gateway: /api/gps/broadcasting/auth).
    | Own middleware: only IdentifyFromGateway (NOT the "api" group: the Pusher
    | auth response is not JSON:API and must not go through NegotiatesJsonApi).
    */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: [
            'prefix' => $apiPrefix,
            'middleware' => [IdentifyFromGateway::class],
        ],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The service runs BEHIND the API Gateway (private, non-exposed network).
        // Its X-Forwarded-* headers are trusted to see the client's real
        // scheme/host/IP.
        // TODO production: restrict 'at' to the Gateway's CIDR instead of '*'.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Every route in the "api" group does JSON:API content negotiation and
        // rebuilds the identity forwarded by the API Gateway.
        $middleware->api(append: [
            IdentifyFromGateway::class,
            NegotiatesJsonApi::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Housekeeping for the GPS volume problem — see config/gps.php and
        // App\Console\Commands\PruneGpsLocations. Ingestion (HU1) always
        // stores every reading; this only prunes old rows of finished trips.
        $schedule->command('gps:prune-locations')
            ->dailyAt('03:15')
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) use ($apiPrefix): void {
        // Any error on a service route is serialized as a JSON:API error
        // document (never HTML, never arbitrary JSON).
        $exceptions->render(function (Throwable $e, Request $request) use ($apiPrefix) {
            if ($request->is($apiPrefix.'/*') || $request->expectsJson()) {
                return JsonApiErrors::fromThrowable($e, config('app.debug'));
            }

            return null;
        });
    })->create();
