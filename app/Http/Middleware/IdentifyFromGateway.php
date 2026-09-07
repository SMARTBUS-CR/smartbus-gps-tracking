<?php

namespace App\Http\Middleware;

use App\Support\Gateway\GatewayUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rebuilds the current user from the headers the API Gateway forwards
 * (config/gateway.php) and installs it as the request's user resolver.
 *
 * From here on, `$request->user()` / `auth()->user()` return a GatewayUser, or
 * null when the Gateway sent no identity.
 *
 * This is NOT an authentication system: it only translates what the Gateway has
 * already validated. Used on the channel-authorization route
 * (/api/gps/broadcasting/auth) and on the whole `api` middleware group.
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
