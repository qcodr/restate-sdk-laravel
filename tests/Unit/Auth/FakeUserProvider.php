<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use SensitiveParameter;

/**
 * An in-memory {@see UserProvider} that resolves {@see FakeUser}s from a fixed id => user map.
 *
 * Only {@see retrieveById()} is meaningful — it is the single path the auth propagation relies
 * on (mirroring `StatefulGuard::onceUsingId()`). The credential/remember-token methods are part
 * of the contract but unused here, so they return inert results.
 */
final class FakeUserProvider implements UserProvider
{
    /** @param array<array-key, FakeUser> $users id => user (numeric ids coerce to int keys) */
    public function __construct(private readonly array $users)
    {
    }

    /** @param mixed $identifier */
    public function retrieveById($identifier): ?Authenticatable
    {
        if (!\is_string($identifier) && !\is_int($identifier)) {
            return null;
        }

        return $this->users[$identifier] ?? null;
    }

    /**
     * @param mixed  $identifier
     * @param string $token
     */
    public function retrieveByToken($identifier, #[SensitiveParameter] $token): ?Authenticatable
    {
        return null;
    }

    /** @param string $token */
    public function updateRememberToken(Authenticatable $user, #[SensitiveParameter] $token): void
    {
    }

    /** @param array<string, mixed> $credentials */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable
    {
        return null;
    }

    /** @param array<string, mixed> $credentials */
    public function validateCredentials(Authenticatable $user, #[SensitiveParameter] array $credentials): bool
    {
        return false;
    }

    /** @param array<string, mixed> $credentials */
    public function rehashPasswordIfRequired(Authenticatable $user, #[SensitiveParameter] array $credentials, bool $force = false): void
    {
    }
}
