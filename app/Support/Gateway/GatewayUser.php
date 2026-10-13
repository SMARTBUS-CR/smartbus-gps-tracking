<?php

namespace App\Support\Gateway;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A "user" resolved from the Bearer token the API Gateway forwards
 * (see App\Http\Middleware\IdentifyFromGateway).
 *
 * It never hits the DB (there is no `users` table here). It is only the subject
 * of the request for channel authorization (routes/channels.php).
 */
final class GatewayUser implements Authenticatable
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public readonly string $id,
        public readonly array $roles = [],
        public readonly ?string $companyId = null,
    ) {}

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    // --- Contract\Auth\Authenticatable ---

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
        // no-op: no session, no "remember me"
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
