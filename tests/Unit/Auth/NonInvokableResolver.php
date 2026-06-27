<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Auth;

/**
 * A non-invokable class used to prove {@see \Qcodr\Restate\Laravel\Auth\RestateContext} rejects a
 * `tenant_resolver` class-string that resolves to something that is not callable.
 */
final class NonInvokableResolver
{
    public function notInvoke(): void
    {
    }
}
