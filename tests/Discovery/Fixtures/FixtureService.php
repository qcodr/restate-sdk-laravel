<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Discovery\Fixtures;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

/**
 * Discovery fixture: a `#[Service]`-attributed class the scanner must find.
 */
#[Service]
final class FixtureService
{
    #[Handler]
    public function ping(Context $ctx): string
    {
        return 'pong';
    }
}
