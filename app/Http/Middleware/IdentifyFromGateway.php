<?php

namespace App\Http\Middleware;

use App\Support\Gateway\GatewayUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reconstruye el usuario a partir de las cabeceras del API Gateway
 * (config/gateway.php) y lo inyecta como user resolver de la request.
 *
 * A partir de aqui `$request->user()` / `auth()->user()` devuelven un
 * GatewayUser, o null si el Gateway no mando identidad.
 *
 * Se usa en la ruta de autorizacion de canales (/gps/broadcasting/auth).
 * NO es un sistema de autenticacion: solo traduce lo que el Gateway ya validó.
 */
class IdentifyFromGateway
{
    public function handle(Request $request, Closure $next): Response
    {
        $headers = config('gateway.headers');

        $userId = $request->headers->get($headers['user_id']);

        if ($userId !== null && $userId !== '' && ctype_digit((string) $userId)) {
            $roles = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $request->headers->get($headers['roles'], ''))
            )));

            $companyId = $request->headers->get($headers['company_id']);
            $companyId = ($companyId !== null && ctype_digit((string) $companyId)) ? (int) $companyId : null;

            $user = new GatewayUser((int) $userId, $roles, $companyId);

            $request->setUserResolver(fn () => $user);
        }

        return $next($request);
    }
}
