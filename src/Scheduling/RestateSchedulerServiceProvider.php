<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Scheduling;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the `Schedule::restate()` macro, letting Laravel's scheduler fire durable Restate
 * invocations on a cron cadence:
 *
 * ```php
 * Schedule::restate('OrderWorkflow', 'run', ['id' => 1])->dailyAt('03:00');
 * ```
 *
 * The macro is a thin adapter over {@see Schedule::call()}: it schedules a closure that, when
 * the tick is due, one-way-sends to the Restate ingress via the shared
 * {@see \Qcodr\Restate\Laravel\Client\RestateClient}. It returns the resulting
 * {@see CallbackEvent} so callers chain Laravel's own frequency builders
 * (`->everyFiveMinutes()`, `->dailyAt(...)`, `->withoutOverlapping()`, …) exactly as they would
 * on any scheduled closure.
 *
 * Every entry is given a stable description (for `schedule:list` and the overlap mutex) and a
 * per-tick idempotency key, both derived by {@see RestateSchedule}; see that class for why the
 * key is bucketed to the minute. The {@see RestateClient} is resolved lazily inside the closure,
 * so defining a schedule builds nothing — the dependency is touched only when a tick fires.
 *
 * Self-contained like the package's other feature sub-providers: it depends only on the shared
 * client bound by the main `RestateServiceProvider`, so that provider can register it with
 * `$this->app->register(RestateSchedulerServiceProvider::class)`. The macro lives in `boot()`
 * (registration is a static, container-free operation) and is guarded against re-registration so
 * repeated boots in a long-lived process or test suite are idempotent.
 */
final class RestateSchedulerServiceProvider extends ServiceProvider
{
    /**
     * The macro name callers invoke as `Schedule::restate(...)` / `$schedule->restate(...)`.
     */
    private const MACRO = 'restate';

    public function boot(): void
    {
        if (Schedule::hasMacro(self::MACRO)) {
            return;
        }

        Schedule::macro(self::MACRO, function (
            string $service,
            string $handler,
            mixed $payload = null,
            ?string $key = null,
        ): CallbackEvent {
            // Rebound to the Schedule instance when the macro is invoked; annotated so static
            // analysis resolves `call()`/`name()` against Schedule, not this provider.
            /** @var Schedule $this */
            return $this->call(RestateSchedule::dispatcher($service, $handler, $payload, $key))
                ->name(RestateSchedule::describe($service, $handler, $key));
        });
    }
}
