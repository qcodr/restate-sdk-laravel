<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Logging;

use Illuminate\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Qcodr\Restate\Laravel\Logging\RestateLogger;
use Qcodr\Restate\Laravel\Logging\RestateObservabilityServiceProvider;
use Qcodr\Restate\Laravel\RestateServiceProvider;
use Qcodr\Restate\Laravel\Tests\TestCase;
use Qcodr\Restate\Sdk\Context\ReplayAwareLogger;

/**
 * Integration-tests the logging half of the observability provider under Testbench: the binding
 * resolves Laravel's logger, a handler's log lands in Laravel's log stack exactly once, the
 * SDK's replay-aware wrapper suppresses replay output over that bound logger, and an opt-in
 * `restate` channel is honoured.
 */
final class RestateLogServiceProviderTest extends TestCase
{
    /**
     * Records captured from Laravel's `MessageLogged` event for the test currently running.
     *
     * @var list<MessageLogged>
     */
    private array $loggedEvents = [];

    /**
     * @param Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RestateServiceProvider::class, RestateObservabilityServiceProvider::class];
    }

    public function testBindsRestateLoggerAsAPsrLoggerSingleton(): void
    {
        $first = app(RestateLogger::class);
        $second = app(RestateLogger::class);

        // RestateLogger is a PSR-3 LoggerInterface (statically guaranteed), bound as a singleton.
        self::assertInstanceOf(RestateLogger::class, $first);
        self::assertSame($first, $second);
    }

    public function testHandlerLogReachesLaravelLogStackExactlyOnce(): void
    {
        $this->listenForLogEvents();

        app(RestateLogger::class)->info('handler ran', ['invocation' => 'inv_1']);

        self::assertCount(1, $this->loggedEvents);
        self::assertSame('handler ran', $this->loggedEvents[0]->message);
        self::assertSame(['invocation' => 'inv_1'], $this->loggedEvents[0]->context);
    }

    public function testReplayAwareWrappingOverTheBoundLoggerLogsOnceAndSuppressesReplay(): void
    {
        $this->listenForLogEvents();

        $processing = true;
        $replayAware = new ReplayAwareLogger(app(RestateLogger::class), static function () use (&$processing): bool {
            return $processing;
        });

        $replayAware->info('emitted while processing');
        $processing = false;
        $replayAware->info('re-emitted during replay');

        self::assertCount(1, $this->loggedEvents);
        self::assertSame('emitted while processing', $this->loggedEvents[0]->message);
    }

    public function testRoutesToTheConfiguredRestateChannelWhenSet(): void
    {
        $logFile = \tempnam(\sys_get_temp_dir(), 'restate-log-');
        self::assertIsString($logFile);

        try {
            config()->set('logging.channels.restate', ['driver' => 'single', 'path' => $logFile, 'level' => 'debug']);
            config()->set('restate.logging.channel', 'restate');
            // Rebuild the singleton so it re-reads the channel from config.
            app()->forgetInstance(RestateLogger::class);

            app(RestateLogger::class)->info('routed to the restate channel');

            $contents = \file_get_contents($logFile);
            self::assertIsString($contents);
            self::assertStringContainsString('routed to the restate channel', $contents);
        } finally {
            if (\is_file($logFile)) {
                \unlink($logFile);
            }
        }
    }

    /**
     * Subscribe to Laravel's `MessageLogged` event and collect the records into
     * {@see self::$loggedEvents}, so a log written through any channel can be asserted without
     * coupling to a specific handler or driver.
     */
    private function listenForLogEvents(): void
    {
        Log::listen(function (MessageLogged $event): void {
            $this->loggedEvents[] = $event;
        });
    }
}
