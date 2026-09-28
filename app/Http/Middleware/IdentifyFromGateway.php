<?php

namespace App\Http\Middleware;

use App\Support\Gateway\GatewayUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Resolves the current user from the Bearer token the API Gateway forwards
 * (config/gateway.php) and installs it as the request's user resolver.
 *
 * From here on, `$request->user()` / `auth()->user()` return a GatewayUser, or
 * null when there is no token or the Authentication Service rejects it.
 *
 * This is NOT an authentication system: the token is only validated by the
 * Authentication Service (GET /api/user). The lookup is lazy (only when someone
 * asks for the user) and cached per token. Used on the channel-authorization
 * route (/api/gps/broadcasting/auth) and on the whole `api` middleware group.
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

    private function resolveUser(string $token): ?GatewayUser
    {
        $cacheKey = 'gateway_user:'.hash('sha256', $token);

        $identity = Cache::get($cacheKey);

        if ($identity === null) {
            $identity = $this->fetchIdentity($token);

            if ($identity === null) {
                return null;
            }

            Cache::put($cacheKey, $identity, config('gateway.auth.cache_ttl'));
        }

        return new GatewayUser($identity['id'], $identity['roles']);
    }

    /**
     * Ask the Authentication Service who owns the token.
     *
     * @return array{id: string, roles: list<string>}|null
     */
    private function fetchIdentity(string $token): ?array
    {
        $config = config('gateway.auth');

        try {
            $response = Http::baseUrl($config['url'])
                ->withToken($token)
                ->acceptJson()
                ->timeout($config['timeout'])
                ->when(app()->isLocal(), fn ($http) => $http->withoutVerifying())
                ->get($config['user_path']);
        } catch (Throwable $e) {
            Log::warning('Authentication Service unreachable while resolving the user.', [
                'error' => $e->getMessage(),
            ]);

            return null;
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
