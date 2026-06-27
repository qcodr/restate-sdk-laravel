<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Qcodr\Restate\Laravel\Logging\RestateLogger;
use Qcodr\Restate\Sdk\Context\ReplayAwareLogger;
use RuntimeException;

/**
 * Unit-tests {@see RestateLogger} in isolation (no Laravel container) by driving it with a
 * recording underlying logger, and verifies that wrapping it in the SDK's
 * {@see ReplayAwareLogger} yields the end-to-end behaviour the parent wiring produces: a
 * handler's log lands in Laravel's logger exactly once, and is suppressed during replay.
 */
final class RestateLoggerTest extends TestCase
{
    public function testForwardsRecordsToTheResolvedUnderlyingLogger(): void
    {
        $sink = new RecordingLogger();

        $logger = new RestateLogger(static fn (): LoggerInterface => $sink);
        $logger->info('handler ran', ['invocation' => 'inv_1']);

        self::assertCount(1, $sink->records);
        self::assertSame(LogLevel::INFO, $sink->records[0]['level']);
        self::assertSame('handler ran', $sink->records[0]['message']);
        self::assertSame(['invocation' => 'inv_1'], $sink->records[0]['context']);
    }

    public function testResolvesTheUnderlyingLoggerOncePerWriteSoTestDoublesAreHonoured(): void
    {
        $first = new RecordingLogger();
        $second = new RecordingLogger();

        // Resolver returns a different logger on each call — proving resolution is per-write,
        // not frozen at construction (the property that lets Log::spy()/listen() intercept).
        $queue = [$first, $second];
        $logger = new RestateLogger(static function () use (&$queue): LoggerInterface {
            return \array_shift($queue) ?? throw new RuntimeException('drained');
        });

        $logger->warning('first');
        $logger->error('second');

        self::assertCount(1, $first->records);
        self::assertCount(1, $second->records);
        self::assertSame('first', $first->records[0]['message']);
        self::assertSame('second', $second->records[0]['message']);
    }

    public function testReplayAwareWrapperSuppressesRecordsEmittedDuringReplay(): void
    {
        $sink = new RecordingLogger();
        $logger = new RestateLogger(static fn (): LoggerInterface => $sink);

        $processing = true;
        $replayAware = new ReplayAwareLogger($logger, static function () use (&$processing): bool {
            return $processing;
        });

        $replayAware->info('emitted while processing');
        $processing = false;
        $replayAware->info('re-emitted during replay');

        self::assertCount(1, $sink->records);
        self::assertSame('emitted while processing', $sink->records[0]['message']);
    }
}
