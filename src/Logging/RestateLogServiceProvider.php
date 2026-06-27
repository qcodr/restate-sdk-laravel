<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Logging;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the {@see RestateLogger} the package feeds to the Restate SDK, so handler logs flow
 * into Laravel's logging stack.
 *
 * Exposed under the concrete {@see RestateLogger} key for explicit type-hinted injection; the
 * parent's one-line wiring resolves it (`app(RestateLogger::class)`) and passes it into the
 * SDK's `RequestProcessor` / `AmpStreamingServer`, where the SDK wraps it in its own
 * replay-aware decorator.
 *
 * A deliberate non-goal: this provider does **not** alias `Psr\Log\LoggerInterface`. Laravel's
 * core already aliases that contract to its framework-wide `LogManager` (see
 * {@see \Illuminate\Foundation\Application::registerCoreContainerAliases()}), so rebinding it
 * would hijack every PSR-3 injection in the application, not just Restate's. The package
 * therefore ships its own concrete key and leaves `LoggerInterface` untouched — a caller who
 * wants the plain default channel can still pass `app(LoggerInterface::class)` instead.
 *
 * The channel is read from `restate.logging.channel`; when that key is absent (the default,
 * since the package's shipped config does not declare it) the default log stack is used, so
 * the integration is zero-config and only routes to a dedicated `restate` channel when the
 * application opts in.
 */
final class RestateLogServiceProvider extends ServiceProvider
{
    /**
     * Config key (dot path) selecting the log channel Restate handler output is routed to.
     */
    private const CHANNEL_CONFIG_KEY = 'restate.logging.channel';

    public function register(): void
    {
        $this->app->singleton(RestateLogger::class, static function (Application $app): RestateLogger {
            $channel = $app->make(Config::class)->get(self::CHANNEL_CONFIG_KEY);

            return RestateLogger::forChannel(\is_string($channel) && $channel !== '' ? $channel : null);
        });
    }
}
