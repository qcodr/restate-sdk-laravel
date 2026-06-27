<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Auth;

use Stringable;

/**
 * A {@see Stringable} tenant value used to prove {@see \Qcodr\Restate\Laravel\Auth\ForwardsAuthHeaders}
 * reduces a rich tenant object to its string form via `(string)` casting.
 */
final class StringableTenant implements Stringable
{
    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
