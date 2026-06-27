<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Logging;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A minimal PSR-3 logger test double that records every record it is handed, standing in for a
 * Laravel log channel so {@see \Qcodr\Restate\Laravel\Logging\RestateLogger}'s forwarding can be
 * asserted without the Laravel container. A named fixture (rather than an anonymous class) so its
 * `$records` shape survives static analysis at the call sites.
 */
final class RecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
