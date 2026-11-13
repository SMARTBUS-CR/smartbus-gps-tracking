<?php

namespace App\Http\Middleware;

use App\Support\Gateway\GatewayUnavailableException;
use App\Support\Gateway\GatewayUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * Resolves the current user from the Bearer token the API Gateway forwards
 * (config/gateway.php) and installs it as the request's user resolver.
 *
 * From here on, `$request->user()` / `auth()->user()` return a GatewayUser, or
 * null when there is no token or the API Gateway rejects it.
 *
 * This is NOT an authentication system: the token is validated by the API
 * Gateway (GET /api/auth/user, behind its `validate.token` middleware), which
 * proxies to the Authentication Service. This service never calls the
 * Authentication Service directly. The lookup is lazy (only when someone asks
 * for the user) and cached per token. Used on the channel-authorization route
 * (/api/gps/broadcasting/auth) and on the whole `api` middleware group.
 */
class IdentifyFromGateway
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token !== null && $token !== '') {
            $resolved = false;
            $user = null;

            $request->setUserResolver(function () use ($token, &$resolved, &$user) {
                if (! $resolved) {
                    $user = $this->resolveUser($token);
                    $resolved = true;
                }

                return $user;
            });
        }

        return $next($request);
    }

    /**
     * The token's user, or null when the API Gateway REJECTS the token.
     *
     * When the Gateway cannot answer (slow, down, 5xx / 429), that is not a
     * rejection: the last identity confirmed for this token is used, so a
     * driver in the middle of a trip is not signed out because the Gateway had
     * a slow moment. Without one, the request fails with a 503 (retryable),
     * never with a 401 (which the app reads as "session expired").
     *
     * @throws ServiceUnavailableHttpException
     */
    private function resolveUser(string $token): ?GatewayUser
    {
        $hash = hash('sha256', $token);
        $cacheKey = 'gateway_user:'.$hash;
        $lastKnownKey = 'gateway_user_last:'.$hash;

        $identity = Cache::get($cacheKey);

        if ($identity === null) {
            try {
                $identity = $this->fetchIdentity($token);
            } catch (GatewayUnavailableException $e) {
                $identity = Cache::get($lastKnownKey);

                if ($identity === null) {
                    throw new ServiceUnavailableHttpException(
                        null,
                        'Authentication could not be confirmed. Try again.',
                        $e,
                    );
                }

                return new GatewayUser($identity['id'], $identity['roles']);
            }

            if ($identity === null) {
                // Rejected for good: forget it, so the fallback above can
                // never let a revoked token in.
                Cache::forget($lastKnownKey);

                return null;
            }

            Cache::put($cacheKey, $identity, config('gateway.identity.cache_ttl'));
            Cache::put($lastKnownKey, $identity, config('gateway.identity.last_known_ttl'));
        }

        return new GatewayUser($identity['id'], $identity['roles']);
    }

    /**
     * Ask the API Gateway who owns the token.
     *
     * @return array{id: string, roles: list<string>}|null null = the token is rejected
     *
     * @throws GatewayUnavailableException The Gateway could not answer.
     */
    private function fetchIdentity(string $token): ?array
    {
        $config = config('gateway.identity');

        try {
            $response = Http::baseUrl($config['url'])
                ->withToken($token)
                ->acceptJson()
                ->timeout($config['timeout'])
                ->when(app()->isLocal(), fn ($http) => $http->withoutVerifying())
                ->get($config['user_path']);
        } catch (Throwable $e) {
            Log::warning('API Gateway unreachable while resolving the user.', [
                'error' => $e->getMessage(),
            ]);

            throw new GatewayUnavailableException($e->getMessage(), previous: $e);
        }

        if ($response->serverError() || $response->status() === 429) {
            Log::warning('API Gateway could not resolve the user.', [
                'status' => $response->status(),
            ]);

            throw new GatewayUnavailableException("Gateway answered {$response->status()}.");
        }

        if (! $response->successful()) {
            return null;
        }

        // User ids are UUIDs (the Auth microservice's `users` table).
        $id = $response->json('data.id');

        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        // Roles come as JSON:API identifiers (numeric ids) under relationships;
        // the role name ("passenger", "driver", ...) is in `included[].attributes.value`.
        $roleNames = collect($response->json('included', []))
            ->where('type', 'roles')
            ->mapWithKeys(fn ($role) => [(string) ($role['id'] ?? '') => $role['attributes']['value'] ?? null]);

        $roles = collect($response->json('data.relationships.roles.data', []))
            ->map(fn ($identifier) => $roleNames->get((string) ($identifier['id'] ?? '')))
            ->filter(fn ($role) => is_string($role) && $role !== '')
            ->values()
            ->all();

        return ['id' => $id, 'roles' => $roles];
    }
}
