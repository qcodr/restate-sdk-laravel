<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Auth;

/**
 * A container-resolvable invokable used to prove {@see \Qcodr\Restate\Laravel\Auth\RestateContext}
 * accepts a `tenant_resolver` given as a class-string and maps the raw header id through it.
 */
final class InvokableTenantResolver
{
    public function __invoke(string $tenantId): string
    {
        return 'resolved:' . $tenantId;
    }
}
