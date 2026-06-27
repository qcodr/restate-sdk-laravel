<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Discovery\Fixtures;

use Qcodr\Restate\Sdk\Context\ObjectContext;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\VirtualObject;

/**
 * Discovery fixture: a `#[VirtualObject]`-attributed class the scanner must find.
 */
#[VirtualObject]
final class FixtureObject
{
    #[Handler]
    public function touch(ObjectContext $ctx): string
    {
        return $ctx->key();
    }
}
