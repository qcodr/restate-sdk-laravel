<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Support;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

/**
 * Minimal Restate service fixture: a stateless greeter used to prove the package binds,
 * discovers, and serves a handler through the Laravel container.
 */
#[Service]
final class GreeterService
{
    #[Handler]
    public function greet(Context $ctx, string $name): string
    {
        return "Hello {$name}";
    }
}
