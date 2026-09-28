<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\IdentifyFromGateway;
use App\Support\Gateway\GatewayUser;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The API Gateway forwards the client's  Authorization: Bearer <token>  as-is.
 * IdentifyFromGateway resolves the user by asking the Authentication Service
 * (GET /api/user?include=roles), lazily and cached per token.
 */
class IdentifyFromGatewayTest extends TestCase
{
    private const AUTH_URL = 'https://smartbus-authentication.test';

    private const USER_ID = '01a09332-3457-7315-885e-4ebb218f7262';

    protected function setUp(): void
    {
        parent::setUp();

        config(['gateway.auth.url' => self::AUTH_URL]);
        Cache::flush();

        Route::middleware(IdentifyFromGateway::class)->get('/_test/whoami', function (Request $request) {
            $user = $request->user();

            return response()->json($user instanceof GatewayUser
                ? ['id' => $user->id, 'roles' => $user->roles]
                : ['id' => null]);
        });

        Route::middleware(IdentifyFromGateway::class)->get('/_test/anonymous', fn () => response()->json(['ok' => true]));
    }

    private function authUserResponse(string $id = self::USER_ID): array
    {
        return [
            'data' => [
                'id' => $id,
                'type' => 'users',
                'attributes' => ['name' => 'Pasajero', 'email' => 'p@smartbus.com'],
                'relationships' => [
                    'roles' => ['data' => [['id' => '4', 'type' => 'roles']]],
                ],
            ],
            // Real shape of the Authentication Service: the role name is in `included`.
            'included' => [
                ['id' => '4', 'type' => 'roles', 'attributes' => ['value' => 'passenger', 'label' => 'Pasajero']],
            ],
        ];
    }

    public function test_it_resolves_the_user_from_the_bearer_token(): void
    {
        Http::fake([self::AUTH_URL.'/api/user*' => Http::response($this->authUserResponse())]);

        $this->withToken('valid-token')->getJson('/_test/whoami')
            ->assertOk()
            ->assertExactJson(['id' => self::USER_ID, 'roles' => ['passenger']]);

        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::AUTH_URL.'/api/user?include=roles'
            && $request->hasHeader('Authorization', 'Bearer valid-token'));
    }

    public function test_it_caches_the_identity_per_token(): void
    {
        Http::fake([self::AUTH_URL.'/api/user*' => Http::response($this->authUserResponse())]);

        $this->withToken('valid-token')->getJson('/_test/whoami')->assertJsonPath('id', self::USER_ID);
        $this->withToken('valid-token')->getJson('/_test/whoami')->assertJsonPath('id', self::USER_ID);

        Http::assertSentCount(1);
    }

    public function test_there_is_no_user_without_a_token(): void
    {
        Http::fake();

        $this->getJson('/_test/whoami')->assertExactJson(['id' => null]);

        Http::assertNothingSent();
    }

    public function test_spoofed_identity_headers_are_ignored(): void
    {
        Http::fake();

        $this->withHeaders(['X-User-Id' => self::USER_ID, 'X-User-Roles' => 'admin'])
            ->getJson('/_test/whoami')
            ->assertExactJson(['id' => null]);
    }

    public function test_there_is_no_user_when_the_auth_service_rejects_the_token(): void
    {
        Http::fake([self::AUTH_URL.'/api/user*' => Http::response(['errors' => []], 401)]);

        $this->withToken('expired-token')->getJson('/_test/whoami')->assertExactJson(['id' => null]);
        $this->withToken('expired-token')->getJson('/_test/whoami')->assertExactJson(['id' => null]);

        // Failures are not cached: a transient error does not lock the user out.
        Http::assertSentCount(2);
    }

    public function test_there_is_no_user_when_the_auth_service_is_unreachable(): void
    {
        Http::fake([self::AUTH_URL.'/api/user*' => fn () => throw new \RuntimeException('auth down')]);

        $this->withToken('valid-token')->getJson('/_test/whoami')->assertExactJson(['id' => null]);
    }

    public function test_a_non_uuid_user_id_is_rejected(): void
    {
        Http::fake([self::AUTH_URL.'/api/user*' => Http::response($this->authUserResponse('42'))]);

        $this->withToken('valid-token')->getJson('/_test/whoami')->assertExactJson(['id' => null]);
    }

    public function test_routes_that_do_not_need_the_user_never_call_the_auth_service(): void
    {
        Http::fake();

        $this->withToken('valid-token')->getJson('/_test/anonymous')->assertOk();

        Http::assertNothingSent();
    }
}
