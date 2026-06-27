<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Discovery\Fixtures;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

/**
 * Discovery fixture: an abstract `#[Service]`-attributed class the scanner must EXCLUDE.
 *
 * It carries a Restate marker attribute but is abstract, so it cannot be bound to the
 * endpoint; {@see \Qcodr\Restate\Laravel\Discovery\ServiceScanner} drops it.
 */
#[Service]
abstract class AbstractFixtureService
{
    #[Handler]
    abstract public function handle(Context $ctx): string;
}
