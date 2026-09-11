<?php

namespace App\Support\Gateway;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A "user" rebuilt from the headers the API Gateway forwards.
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
        public readonly int $id,
        public readonly array $roles = [],
        public readonly ?int $companyId = null,
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

    public function getAuthIdentifier(): int
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
