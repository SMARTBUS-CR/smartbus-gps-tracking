<?php

use App\Models\Trip;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\post;
use function Pest\Laravel\withHeaders;
use function Pest\Laravel\withToken;

/*
| HU2 — authorization of the PRIVATE channel `trip.{tripId}` (routes/channels.php),
| requested by Laravel Echo through the Gateway at POST /api/gps/broadcasting/auth.
|
| phpunit.xml disables broadcasting (null driver), so each test switches to a
| Reverb driver with dummy credentials and re-registers the channels on it.
*/

const BROADCAST_AUTH_URL = '/api/broadcasting/auth';
const PASSENGER_ID = '01a0c691-579f-731c-a034-03cc8fe8136f';

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'gateway.auth.url' => 'https://smartbus-authentication.test',
    ]);

    app(BroadcastManager::class)->purge();
    require base_path('routes/channels.php');

    Cache::flush();
    Http::fake([
        // The Authentication Service knows `passenger-token` and rejects any other token.
        'https://smartbus-authentication.test/api/user*' => fn (ClientRequest $request) => $request->hasHeader('Authorization', 'Bearer passenger-token')
            ? Http::response(authServiceUser(PASSENGER_ID))
            : Http::response(['errors' => [['status' => '401']]], 401),
    ]);
});

/**
 * Laravel Echo sends the subscription as application/x-www-form-urlencoded.
 *
 * @return array<string, string>
 */
function channelSubscription(string $tripId): array
{
    return ['socket_id' => '1234.5678', 'channel_name' => "private-trip.{$tripId}"];
}

it('signs the subscription of an authenticated user to an active trip', function (string $status) {
    $trip = Trip::factory()->create(['status' => $status]);

    withToken('passenger-token')
        ->post(BROADCAST_AUTH_URL, channelSubscription($trip->id), ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonStructure(['auth'])
        ->assertJsonPath('auth', fn (string $auth) => str_starts_with($auth, 'test-key:'));
})->with(['scheduled', 'in_progress']);

it('denies a trip that is no longer active', function () {
    $trip = Trip::factory()->completed()->create();

    withToken('passenger-token')
        ->post(BROADCAST_AUTH_URL, channelSubscription($trip->id), ['Accept' => 'application/json'])
        ->assertForbidden();
});

it('denies a trip that does not exist or whose id is not a UUID', function (string $tripId) {
    withToken('passenger-token')
        ->post(BROADCAST_AUTH_URL, channelSubscription($tripId), ['Accept' => 'application/json'])
        ->assertForbidden();
})->with([
    'unknown trip' => fn () => (string) Str::uuid7(),
    'not a UUID' => '42',
]);

it('denies a request without a bearer token', function () {
    $trip = Trip::factory()->create();

    post(BROADCAST_AUTH_URL, channelSubscription($trip->id), ['Accept' => 'application/json'])
        ->assertForbidden();

    Http::assertNothingSent();
});

it('denies a request that only carries spoofed identity headers', function () {
    $trip = Trip::factory()->create();

    withHeaders(['X-User-Id' => PASSENGER_ID, 'X-User-Roles' => 'super-admin', 'Accept' => 'application/json'])
        ->post(BROADCAST_AUTH_URL, channelSubscription($trip->id))
        ->assertForbidden();
});

it('denies a token rejected by the Authentication Service', function () {
    $trip = Trip::factory()->create();

    withToken('expired-token')
        ->post(BROADCAST_AUTH_URL, channelSubscription($trip->id), ['Accept' => 'application/json'])
        ->assertForbidden();
});
