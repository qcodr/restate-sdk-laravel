# Use case: fire durable Restate invocations from Laravel's scheduler

Laravel's scheduler is great at *deciding when* something should happen — `everyFiveMinutes()`,
`dailyAt('03:00')`, `weekdays()`. It is a poor place to *do durable work*: the closure runs in a
single `schedule:run` process, and if that process dies mid-flight the work is simply lost, with
no journal, no retry, no compensation.

This integration draws the line in the right place: the **scheduler triggers**, a **Restate
handler does the durable work**. The `Schedule::restate()` macro turns any scheduled tick into a
one-way dispatch to the Restate ingress, so the cron cadence stays in Laravel while exactly-once
side effects, retries, and recovery live in the durable handler.

```php
use Illuminate\Console\Scheduling\Schedule;

// In routes/console.php (Laravel 11+) or app/Console/Kernel.php::schedule():
Schedule::restate('OrderWorkflow', 'run', ['cutoff' => 'today'])->dailyAt('03:00');
```

That single line schedules a daily, durable kick-off of `OrderWorkflow::run` — the nightly batch
the workflow itself drives with durable steps.

## Enable the macro

The macro is registered by `RestateSchedulerServiceProvider`. With package auto-discovery it is
wired in by the main `RestateServiceProvider`; nothing else is required. From that point on,
`Schedule::restate(...)` (and `$schedule->restate(...)`) is available wherever you define
scheduled tasks.

It relies on the same ingress configuration as every other caller — see
[dispatch.md](dispatch.md#configure-the-ingress) — because under the hood it calls
`RestateClient::send()`.

## The macro

```php
Schedule::restate(
    string $service,        // the #[Service] / #[VirtualObject] / #[Workflow] name
    string $handler,        // the handler method
    mixed  $payload = null, // JSON-encoded as the single argument (null ⇒ empty body)
    ?string $key   = null,  // object/workflow key, or null for a plain Service
): \Illuminate\Console\Scheduling\CallbackEvent;
```

It returns Laravel's own `CallbackEvent`, so you chain the **frequency** (and any other event)
methods exactly as you would on `Schedule::call(...)`:

```php
Schedule::restate('ReportService', 'rollup')->hourly();
Schedule::restate('BillingObject', 'sweep', null, 'eu-west')->dailyAt('02:30');
Schedule::restate('CacheService', 'warm')->everyFiveMinutes()->weekdays();
```

Each entry maps to the ingress one-way send documented in [dispatch.md](dispatch.md):
`POST {ingress}/{Service}/{handler}/send`, or `…/{Service}/{key}/{handler}/send` when a key is
given.

## What the macro adds for you

### A readable, stable name

Every entry is named after its target, mirroring the ingress path, so `php artisan schedule:list`
reads naturally:

```text
0 3 * * *  Restate dispatch OrderWorkflow/run
*/5 * * * *  Restate dispatch CacheService/warm
```

The name is stable for the life of the entry, which is also what lets you add
`->withoutOverlapping()` (Laravel derives the overlap mutex from the description).

### A per-tick idempotency key

The macro attaches an `Idempotency-Key` to every dispatch, derived from the target plus a
**minute-resolution** time bucket: `restate-schedule:{Service}/{key?}/{handler}@{YYYY-MM-DDTHH:MM}`
(computed in UTC).

This is the deliberate choice behind "double-tick de-dupes":

- **Why bucketed, not constant?** A constant key would let a daily job run *once, ever* — the
  second day's tick would be deduped against the first. The bucket rotates, so each legitimate
  tick gets a fresh key and still runs.
- **Why the minute, not the day?** The minute is the finest cadence Laravel's scheduler can
  dispatch. Bucketing there means two firings of the *same* tick — an overlapping `schedule:run`,
  a duplicated cron entry, a server that briefly ran two schedulers — produce the *same* key, and
  Restate's ingress runs the handler **once**. A coarser day-bucket would wrongly suppress every
  run after the first for any schedule finer than daily (`everyFiveMinutes`, `hourly`, …).
- **Why UTC?** So the bucket is stable and DST-agnostic regardless of the app timezone — a clock
  that springs forward must never re-mint a key for a tick that already ran.

Restate retains idempotency keys for a bounded window (its own retention), so these minute-scoped
keys rotate out naturally rather than accumulating.

## Why this beats a stateless Artisan command

A common alternative is `Schedule::command('app:do-the-thing')` running a Job or inline logic.
Compared with dispatching to a Restate handler:

| | Stateless command/job on a tick | `Schedule::restate(...)` → handler |
|--|---------------------------------|------------------------------------|
| Crash mid-run | work is lost; partial side effects linger | the handler resumes from its journal; steps are not re-run |
| Retries | you hand-roll them (backoff, max attempts, dead-letter) | the handler's durable steps retry with Restate's guarantees |
| Double fire | re-executes unless you build your own guard | the ingress idempotency key collapses it to one invocation |
| Multi-step / compensation | bespoke saga code in the command | a workflow/saga handler with durable, compensatable steps |
| Server restart | a tick caught mid-flight is gone | once the ingress has accepted the send, the work survives |

In short: the scheduler decides *when*; Restate guarantees the *what* actually completes.

> **Trigger, not runtime.** The macro is fire-and-forget — it returns as soon as the ingress
> accepts the invocation, and the invocation id it hands back is discarded. The durability lives
> in the Restate handler, not in the scheduled tick. If you need to *await* a result on a tick, or
> branch on it, dispatch from your own command with `RestateClient::call()` instead (see
> [dispatch.md](dispatch.md)); the macro is purpose-built for kicking off durable background work
> on a cadence.

## Resilience notes

- **`->withoutOverlapping()`** guards against a long-running *previous* tick on a single host
  (process-local mutex); the **idempotency key** guards against a *double* tick (including across
  hosts, at the ingress). They are complementary — use both for a job that must never double up.
- **`->onOneServer()`** is still available (the entry has a stable name) if you run the scheduler
  on multiple boxes and want only one to issue the dispatch; even without it, the idempotency key
  means a duplicated dispatch in the same minute is deduped by Restate.
- The `RestateClient` is resolved lazily when a tick fires, so defining a schedule builds nothing
  and an unreachable ingress only ever affects the tick that runs — surfaced as the same
  `RestateRequestException` / `ConnectionException` documented in [dispatch.md](dispatch.md#errors).
