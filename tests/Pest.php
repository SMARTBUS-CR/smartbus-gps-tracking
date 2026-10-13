<?php

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Feature tests are bound to Tests\TestCase, which refreshes the disposable
| PostgreSQL + PostGIS testing database (never the shared operations DB).
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** API Gateway base URL in tests (forced in phpunit.xml as GATEWAY_URL). */
const GATEWAY_URL = 'https://smartbus-gateway.test';

/** Gateway route that resolves the user of a token (config gateway.identity). */
const GATEWAY_USER_URL = GATEWAY_URL.'/api/auth/user';

/**
 * JSON:API body of POST /api/locations.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function gpsLocationPayload(string $tripId, array $attributes = []): array
{
    return [
        'data' => [
            'type' => 'gps-locations',
            'attributes' => array_merge([
                'trip_id' => $tripId,
                'latitude' => 9.93,
                'longitude' => -84.08,
                'speed_kmh' => 41.2,
                'recorded_at' => '2026-09-07T12:00:00Z',
            ], $attributes),
        ],
    ];
}

/**
 * JSON:API body returned by the API Gateway for GET /api/auth/user?include=roles
 * (proxied from the Authentication Service).
 *
 * @return array<string, mixed>
 */
function authServiceUser(string $id, string $role = 'passenger'): array
{
    return [
        'data' => [
            'id' => $id,
            'type' => 'users',
            'attributes' => ['name' => 'Test User', 'email' => 'user@smartbus.test'],
            'relationships' => [
                'roles' => ['data' => [['id' => '4', 'type' => 'roles']]],
            ],
        ],
        // Real shape of the Authentication Service: the role name is in `included`.
        'included' => [
            ['id' => '4', 'type' => 'roles', 'attributes' => ['value' => $role, 'label' => ucfirst($role)]],
        ],
    ];
}

/**
 * Fakes the API Gateway: it recognizes only [$token] (as [$userId] with [$role])
 * and rejects any other token with 401, like its `validate.token` middleware.
 * Also sends that token on every request of the current test.
 */
function actingAsGatewayUser(
    string $token = 'test-token',
    string $userId = '01a0c691-579f-731c-a034-03cc8fe8136f',
    string $role = 'driver',
): void {
    Http::fake([
        GATEWAY_USER_URL.'*' => fn (ClientRequest $request) => $request->hasHeader('Authorization', "Bearer {$token}")
            ? Http::response(authServiceUser($userId, $role))
            : Http::response(['message' => 'Invalid or expired token.'], 401),
    ]);

    test()->withToken($token);
}
