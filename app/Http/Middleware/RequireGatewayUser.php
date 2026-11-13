<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects the request with a JSON:API 401 unless it carries a Bearer token the
 * API Gateway recognizes (resolved by IdentifyFromGateway).
 *
 * The Gateway already validates tokens, but this service is reachable on its
 * own URL too: this keeps anyone without a valid token from calling it
 * directly (e.g. sending fake GPS readings).
 */
class RequireGatewayUser
{
    /**
     * @throws AuthenticationException Rendered as a JSON:API 401 (bootstrap/app.php).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
