<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Scheduling;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Qcodr\Restate\Laravel\Client\RestateClient;

/**
 * Pure logic behind the `Schedule::restate()` macro. Turns a dispatch target — a
 * (service, handler, key) triple plus its payload — into the two derived values a scheduled
 * Restate invocation needs:
 *
 *  - a stable, human-readable **description** so `schedule:list` and the overlap mutex have a
 *    meaningful, non-changing name for the entry;
 *  - a per-tick **idempotency key** so a double-fire of the *same* scheduler tick collapses to
 *    a single durable Restate invocation, while the next legitimate tick still runs.
 *
 * This lives apart from {@see RestateSchedulerServiceProvider} so the macro body stays a thin
 * adapter and the only part with real decisions (the idempotency window) is unit-testable in
 * isolation, without booting Laravel's scheduler.
 *
 * @internal implementation detail of the `Schedule::restate()` macro, not a public API
 */
final class RestateSchedule
{
    /**
     * Namespace prefix for the idempotency key, keeping scheduler-issued keys distinct from any
     * domain key an application sends directly through {@see RestateClient::send()}.
     */
    private const KEY_PREFIX = 'restate-schedule';

    /**
     * Time-bucket format for the idempotency key, at **minute** resolution (`YYYY-MM-DDTHH:MM`).
     *
     * Minute granularity is the deliberate choice here: it matches the finest cadence Laravel's
     * scheduler can dispatch (`everyMinute()`), so two firings inside the same minute — an
     * overlapping `schedule:run`, a duplicated cron entry, a retried tick — produce the *same*
     * key and Restate's ingress runs the handler once. The next minute (the next legitimate
     * tick of any sub-daily schedule) rolls to a fresh key, so a `dailyAt('03:00')` still fires
     * each day and an `everyFiveMinutes()` still fires each interval. A coarser day-bucket would
     * wrongly suppress every run after the first for any schedule finer than daily.
     */
    private const BUCKET_FORMAT = 'Y-m-d\TH:i';

    /**
     * Timezone the minute-bucket is computed in. Fixed to UTC so the key is stable and
     * DST-agnostic regardless of the application's configured timezone — a clock that springs
     * forward must never re-mint a key for a tick that already ran.
     */
    private const CLOCK_TIMEZONE = 'UTC';

    /**
     * Build the deferred dispatch callback handed to {@see \Illuminate\Console\Scheduling\Schedule::call()}.
     *
     * The returned closure is what runs on each due tick: it resolves the shared
     * {@see RestateClient} from the container (lazily, so nothing is built at schedule-definition
     * time) and fires a one-way {@see RestateClient::send()} with a freshly computed,
     * minute-bucketed idempotency key. The invocation id the ingress returns is intentionally
     * discarded — a scheduled dispatch is fire-and-forget, and the durable work lives in the
     * Restate handler, not here.
     *
     * @param string      $service the `#[Service]` / `#[VirtualObject]` / `#[Workflow]` name
     * @param string      $handler the handler method name
     * @param mixed       $payload the handler argument; JSON-encoded by the client (null ⇒ empty body)
     * @param string|null $key     object/workflow key, or null for an unkeyed Service
     */
    public static function dispatcher(string $service, string $handler, mixed $payload, ?string $key): Closure
    {
        return static function () use ($service, $handler, $payload, $key): void {
            $now = new DateTimeImmutable('now', new DateTimeZone(self::CLOCK_TIMEZONE));

            // `app()` is a Laravel helper (kept unqualified so Larastan types the return as
            // RestateClient); the dispatch is one-way, so the returned invocation id is dropped.
            app(RestateClient::class)->send(
                $service,
                $handler,
                $payload,
                $key,
                self::idempotencyKey($service, $handler, $key, $now),
            );
        };
    }

    /**
     * Stable, human-readable description for the scheduled entry, mirroring the ingress path so
     * `schedule:list` reads naturally (e.g. `Restate dispatch OrderWorkflow/order-1/run`). It is
     * fixed for the lifetime of the entry — unlike the idempotency key it carries no timestamp —
     * which is also what lets `withoutOverlapping()` derive a consistent mutex name from it.
     *
     * @param string      $service the target service/object/workflow name
     * @param string      $handler the handler method name
     * @param string|null $key     object/workflow key, or null for an unkeyed Service
     */
    public static function describe(string $service, string $handler, ?string $key): string
    {
        return \sprintf('Restate dispatch %s', self::target($service, $handler, $key));
    }

    /**
     * Idempotency key for one scheduler tick: the target plus the minute-resolution bucket of
     * `$at`. Two calls with the same target inside the same minute yield an identical key (so a
     * double-tick dedupes at the Restate ingress); a different minute, or a different target,
     * yields a different key. See {@see self::BUCKET_FORMAT} for why minute resolution.
     *
     * @param string            $service the target service/object/workflow name
     * @param string            $handler the handler method name
     * @param string|null       $key     object/workflow key, or null for an unkeyed Service
     * @param DateTimeInterface $at       the moment the tick fires (bucketed to the minute, in UTC)
     */
    public static function idempotencyKey(string $service, string $handler, ?string $key, DateTimeInterface $at): string
    {
        return \sprintf(
            '%s:%s@%s',
            self::KEY_PREFIX,
            self::target($service, $handler, $key),
            $at->format(self::BUCKET_FORMAT),
        );
    }

    /**
     * Render the invocation target as a slash-joined path mirroring the ingress shape:
     * `Service/handler` for a plain Service, `Service/key/handler` for a keyed Virtual Object or
     * Workflow. Shared by {@see self::describe()} and {@see self::idempotencyKey()} so both
     * always agree on what "the same target" means.
     */
    private static function target(string $service, string $handler, ?string $key): string
    {
        return $key === null
            ? \sprintf('%s/%s', $service, $handler)
            : \sprintf('%s/%s/%s', $service, $key, $handler);
    }
}
