<?php

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
 * JSON:API body returned by the Authentication Service for GET /api/user?include=roles.
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
