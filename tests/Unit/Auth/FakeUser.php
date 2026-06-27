<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A minimal {@see Authenticatable} so the auth helpers can be exercised without Eloquent, a
 * database, or a real User model. Only identity matters for these tests, so the password and
 * remember-token surface is inert.
 */
final class FakeUser implements Authenticatable
{
    public function __construct(private readonly string $id)
    {
    }

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

    /** @param mixed $value */
    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
