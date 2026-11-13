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

describe('the API Gateway cannot answer (it is not a rejection)', function () {
    /** Forgets the 5-minute identity cache, keeping the last known one. */
    function expireIdentityCache(string $token): void
    {
        Cache::forget('gateway_user:'.hash('sha256', $token));
    }

    it('answers 503 (retryable), never 401, when it never confirmed the token', function ($gateway) {
        Http::fake([GATEWAY_USER_URL.'*' => $gateway]);

        withToken('valid-token')->getJson('/_test/whoami')
            ->assertStatus(503)
            ->assertJsonPath('errors.0.status', '503');
    })->with([
        'unreachable / timeout' => fn () => fn () => throw new RuntimeException('cURL error 28: timed out'),
        '5xx' => fn () => Http::response(['message' => 'down'], 502),
        '429' => fn () => Http::response(['message' => 'slow down'], 429),
    ]);

    it('uses the last identity confirmed for the token', function () {
        Http::fakeSequence(GATEWAY_USER_URL.'*')
            ->push(authServiceUser(USER_ID))
            ->whenEmpty(fn () => throw new RuntimeException('cURL error 28: timed out'));

        withToken('valid-token')->getJson('/_test/whoami')->assertJsonPath('id', USER_ID);
        expireIdentityCache('valid-token');

        // The Gateway is slow now: the driver keeps working.
        withToken('valid-token')->getJson('/_test/whoami')
            ->assertOk()
            ->assertExactJson(['id' => USER_ID, 'roles' => ['passenger']]);
    });

    it('a token the Gateway rejects loses its last identity (a revoked token never gets in)', function () {
        Http::fakeSequence(GATEWAY_USER_URL.'*')
            ->push(authServiceUser(USER_ID))
            ->push(['errors' => []], 401)
            ->whenEmpty(fn () => throw new RuntimeException('cURL error 28: timed out'));

        withToken('valid-token')->getJson('/_test/whoami')->assertJsonPath('id', USER_ID);
        expireIdentityCache('valid-token');
        withToken('valid-token')->getJson('/_test/whoami')->assertExactJson(['id' => null]);

        withToken('valid-token')->getJson('/_test/whoami')->assertStatus(503);
    });

    it('waits up to 15 s for the Gateway by default', function () {
        expect(config('gateway.identity.timeout'))->toBe(15);
    });
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
