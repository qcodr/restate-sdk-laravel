<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Logging;

use Illuminate\Foundation\Application;
use Qcodr\Restate\Laravel\Logging\RestateLogger;
use Qcodr\Restate\Laravel\Logging\RestateObservabilityServiceProvider;
use Qcodr\Restate\Laravel\RestateServiceProvider;
use Qcodr\Restate\Laravel\Telescope\RestateWatcher;
use Qcodr\Restate\Laravel\Tests\TestCase;

/**
 * Verifies the observability provider degrades cleanly when Laravel Telescope is not installed
 * (the default in this package's test environment): the application boots, the logger binding is
 * still wired, and the Telescope watcher's `register()` is a guarded no-op rather than an error.
 */
final class RestateObservabilityServiceProviderTest extends TestCase
{
    /**
     * @param Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RestateServiceProvider::class, RestateObservabilityServiceProvider::class];
    }

    public function testBootsAndWiresLoggingWithoutTelescopeInstalled(): void
    {
        // Precondition: Telescope is genuinely absent, so the boot path under test is the
        // guarded one. (If this ever fails, the no-op assertion below would be vacuous.)
        self::assertFalse(
            \class_exists('Laravel\\Telescope\\Telescope'),
            'Telescope must be absent for this test to be meaningful',
        );

        // The provider booted during setUp without error and still registered the logger.
        self::assertInstanceOf(RestateLogger::class, app(RestateLogger::class));
    }

    public function testWatcherRegisterIsAGuardedNoOpWhenTelescopeIsAbsent(): void
    {
        $watcher = new RestateWatcher();

        // Must not throw, and must not even touch the container (it returns before resolving
        // the ingress base URL). Reaching the assertion proves the guard short-circuited.
        $watcher->register(app());

        self::assertInstanceOf(RestateLogger::class, app(RestateLogger::class));
    }
}
