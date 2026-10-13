<?php

use App\Http\Middleware\IdentifyFromGateway;
use App\Support\Gateway\GatewayUser;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;
use function Pest\Laravel\withToken;

/*
| The API Gateway forwards the client's  Authorization: Bearer <token>  as-is.
| IdentifyFromGateway resolves the user by asking the Authentication Service
| through the API Gateway (GET /api/auth/user?include=roles), lazily and
| cached per token. It never calls the Authentication Service directly.
*/

const USER_ID = '01a09332-3457-7315-885e-4ebb218f7262';

beforeEach(function () {
    Cache::flush();

    Route::middleware(IdentifyFromGateway::class)->get('/_test/whoami', function (Request $request) {
        $user = $request->user();

        return response()->json($user instanceof GatewayUser
            ? ['id' => $user->id, 'roles' => $user->roles]
            : ['id' => null]);
    });

    Route::middleware(IdentifyFromGateway::class)->get('/_test/anonymous', fn () => response()->json(['ok' => true]));
});

it('resolves the user and role names from the bearer token', function () {
    Http::fake([GATEWAY_USER_URL.'*' => Http::response(authServiceUser(USER_ID))]);

    withToken('valid-token')->getJson('/_test/whoami')
        ->assertOk()
        ->assertExactJson(['id' => USER_ID, 'roles' => ['passenger']]);

    Http::assertSent(fn (ClientRequest $request) => $request->url() === GATEWAY_USER_URL.'?include=roles'
        && $request->hasHeader('Authorization', 'Bearer valid-token'));
});

it('caches the identity per token', function () {
    Http::fake([GATEWAY_USER_URL.'*' => Http::response(authServiceUser(USER_ID))]);

    withToken('valid-token')->getJson('/_test/whoami')->assertJsonPath('id', USER_ID);
    withToken('valid-token')->getJson('/_test/whoami')->assertJsonPath('id', USER_ID);

    Http::assertSentCount(1);
});

it('has no user without a token', function () {
    Http::fake();

    getJson('/_test/whoami')->assertExactJson(['id' => null]);

    Http::assertNothingSent();
});

it('ignores spoofed identity headers', function () {
    Http::fake();

    withHeaders(['X-User-Id' => USER_ID, 'X-User-Roles' => 'admin'])
        ->getJson('/_test/whoami')
        ->assertExactJson(['id' => null]);
});

it('has no user when the API Gateway rejects the token, and does not cache the failure', function () {
    Http::fake([GATEWAY_USER_URL.'*' => Http::response(['errors' => []], 401)]);

    withToken('expired-token')->getJson('/_test/whoami')->assertExactJson(['id' => null]);
    withToken('expired-token')->getJson('/_test/whoami')->assertExactJson(['id' => null]);

    // A transient error must not lock the user out.
    Http::assertSentCount(2);
});

it('has no user when the API Gateway is unreachable', function () {
    Http::fake([GATEWAY_USER_URL.'*' => fn () => throw new RuntimeException('auth down')]);

    withToken('valid-token')->getJson('/_test/whoami')->assertExactJson(['id' => null]);
});

it('rejects a user id that is not a UUID', function () {
    Http::fake([GATEWAY_USER_URL.'*' => Http::response(authServiceUser('42'))]);

    withToken('valid-token')->getJson('/_test/whoami')->assertExactJson(['id' => null]);
});

it('never calls the API Gateway on routes that do not need the user', function () {
    Http::fake();

    withToken('valid-token')->getJson('/_test/anonymous')->assertOk();

    Http::assertNothingSent();
});
