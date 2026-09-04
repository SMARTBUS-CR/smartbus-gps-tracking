<?php

namespace App\Support\Gateway;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * "Usuario" reconstruido a partir de las cabeceras que reenvia el API Gateway.
 *
 * NO consulta la BD (no hay tabla `users` aqui). Es solo el sujeto de la
 * request para las autorizaciones de canal (routes/channels.php).
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
        // no-op: sin sesion, sin "remember me"
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
