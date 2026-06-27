<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Logging;

use Closure;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

/**
 * The PSR-3 logger the package hands to the Restate SDK so handler logs land in Laravel's
 * logging stack.
 *
 * This is a thin lazy accessor over a Laravel log channel, not a replay filter. The SDK's
 * own {@see \Qcodr\Restate\Sdk\Context\ReplayAwareLogger} already wraps whatever logger it is
 * given and drops records emitted during replay, so a handler's `ctx->logger()->info(...)`
 * reaches this logger — and therefore Laravel — exactly once. All this class adds is the
 * choice of channel.
 *
 * The underlying channel is resolved through an injected closure rather than captured at
 * construction, so the live channel configuration (and test doubles such as `Log::spy()` or
 * `Log::listen()`) is honoured at the moment a record is written, not frozen when the
 * singleton is built.
 */
final class RestateLogger implements LoggerInterface
{
    use LoggerTrait;

    /**
     * @param Closure(): LoggerInterface $resolver yields the underlying Laravel channel
     *                                             logger, resolved fresh per write
     */
    public function __construct(private readonly Closure $resolver)
    {
    }

    /**
     * Build a logger bound to a named Laravel channel, or the default stack when `$channel`
     * is null. The channel is looked up via the {@see Log} facade on each write, so the app's
     * `config/logging.php` (and any `restate` channel defined there) governs where records go.
     *
     * @param string|null $channel a `config/logging.php` channel name, or null for the default
     */
    public static function forChannel(?string $channel): self
    {
        return new self(static fn (): LoggerInterface => $channel === null || $channel === ''
            ? Log::channel()
            : Log::channel($channel));
    }

    /**
     * Forward a record to the resolved Laravel channel. The level/message/context are passed
     * straight through; Laravel's logger applies its own formatting and handlers.
     *
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        ($this->resolver)()->log($level, $message, $context);
    }
}
