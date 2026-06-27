<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Logging;

use Illuminate\Support\ServiceProvider;
use Qcodr\Restate\Laravel\Telescope\RestateWatcher;

/**
 * The single observability entry point the parent registers alongside
 * {@see \Qcodr\Restate\Laravel\RestateServiceProvider}. It wires both halves of Restate
 * observability:
 *
 *  - **Logging** — delegates to {@see RestateLogServiceProvider} to bind the {@see RestateLogger}
 *    the SDK is fed, so handler logs reach Laravel's channels (replay-suppressed by the SDK).
 *  - **Telescope** — when (and only when) Telescope is installed, registers {@see RestateWatcher}
 *    so Restate ingress dispatches gain `restate:*` tags in Telescope.
 *
 * Telescope is optional. Its presence is probed with a string-literal {@see \class_exists()}
 * call (never a `::class` constant), so neither this provider nor static analysis requires the
 * `laravel/telescope` package — when it is absent, {@see self::boot()} simply skips the watcher.
 */
final class RestateObservabilityServiceProvider extends ServiceProvider
{
    /**
     * Telescope's entry point, referenced by string so the optional package is never required
     * for this provider to load.
     */
    private const TELESCOPE_CLASS = 'Laravel\\Telescope\\Telescope';

    public function register(): void
    {
        $this->app->register(RestateLogServiceProvider::class);
    }

    public function boot(): void
    {
        if (!\class_exists(self::TELESCOPE_CLASS)) {
            return;
        }

        (new RestateWatcher())->register($this->app);
    }
}
