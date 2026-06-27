<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Discovery\Fixtures;

/**
 * Discovery fixture: a plain, non-attributed class the scanner must exclude.
 */
final class PlainClass
{
    public function noop(): void
    {
    }
}
