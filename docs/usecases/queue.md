# Use case: run Laravel queued jobs on Restate

The [dispatch](dispatch.md) use case starts a *Restate handler* from Laravel. This one goes
the other way: it lets your existing **`ShouldQueue` jobs** run on Restate without rewriting
them. A job dispatched on the `restate` queue connection is serialised exactly as any other
driver serialises it, shipped to a Restate service, and executed there with **durable,
exactly-once** semantics and **restart-surviving retries** — no `queue:work` worker, no
Redis/database backlog.

> **When to reach for this.** You already have jobs (`SendInvoice`, `ReconcileOrder`, …) and
> want them to survive process restarts and retry durably, without porting each one into a
> Restate `#[Workflow]`/`#[Service]` by hand. Point them at the `restate` connection and the
> existing `handle()` runs unchanged. When a job genuinely needs *durable orchestration*
> inside itself (compensation, signals, sub-steps), write it as a real handler instead — see
> [saga.md](saga.md).

## How it works

```
dispatch((new SendInvoice($id))->onConnection('restate'))
        │
        ▼
RestateQueue::push()                    ← serialises the job (Laravel's createPayload)
        │  POST {ingress}/JobRunner/run/send   (Idempotency-Key: <job uuid>)
        ▼
Restate runtime  ── persists, runs once, retries on failure ──►  JobRunner::run()
                                                                     │  Context::run('handle', …)
                                                                     ▼
                                                                CallQueuedHandler::call()
                                                                     ▼
                                                                SendInvoice::handle()
```

Two pieces do the work, both in `Qcodr\Restate\Laravel\Queue`:

- **`RestateQueue`** — the queue *connection*. Its `push()` / `later()` serialise the job and
  hand it to the Restate ingress (via `RestateClient::send()`) as a fire-and-forget
  invocation. Its read-side (`pop()`, `size()`, …) is inert by design: Restate owns the
  backlog, so there is nothing to poll and **no `queue:work` to run**.
- **`JobRunner`** — a Restate `#[Service]` whose `run` handler is where the invocation lands.
  It rebuilds the job from the payload and runs it through Laravel's own
  `CallQueuedHandler` — inside a single `Context::run()` step, which is what makes the work
  durable and exactly-once.

## Configure

### 1. Register the queue driver provider

`RestateQueueServiceProvider` adds the `restate` driver. The main `RestateServiceProvider`
registers it (or register it yourself in a host app):

```php
// In a service provider's register()/boot():
$this->app->register(\Qcodr\Restate\Laravel\Queue\RestateQueueServiceProvider::class);
```

### 2. Expose the `JobRunner` as a Restate service

It must be discoverable and served like any other handler — add it to `config/restate.php`:

```php
// config/restate.php
'services' => [
    \Qcodr\Restate\Laravel\Queue\JobRunner::class,
    // …your own services
],
```

This is the service the runtime invokes; it is served by the same in-app route or
`restate:serve` server as the rest of your handlers, and registered with Restate as a
deployment in the usual way.

### 3. Add the queue connection

```php
// config/queue.php
'connections' => [
    'restate' => [
        'driver'  => 'restate',
        'service' => 'JobRunner', // Restate service name (JobRunner's #[Service] name)
        'handler' => 'run',       // handler method on that service
        'queue'   => 'default',   // cosmetic label; Restate routes by service, not queue
    ],
],
```

The ingress URL/token are the same `restate.ingress` settings the [dispatch](dispatch.md)
client uses — nothing extra to configure.

## Dispatch a job

Any ordinary job works — it does not need to know it runs on Restate:

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Bus\Queueable;

final class SendInvoice implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(private readonly string $orderId) {}

    public function handle(): void
    {
        // …your normal job body. Runs inside a durable Restate step.
    }
}
```

Send it to Restate per-dispatch:

```php
dispatch((new SendInvoice($order->id))->onConnection('restate'));
```

…or make it the default for a job class (`public $connection = 'restate';`), or set
`queue.default` to `restate` to route everything there.

## Delays

`later()` (and `->delay()`) become a **durable, restart-surviving timer** on the Restate
side — the schedule outlives process restarts without a worker holding it:

```php
// Run in one hour.
dispatch((new SendInvoice($order->id))->onConnection('restate')->delay(now()->addHour()));
```

The delay is forwarded to the ingress as `?delay=<n>ms`.

## Idempotency

Every serialised job carries a `uuid`; the connection sends it as the ingress
`Idempotency-Key`. So if the **same push** fires twice — a retried HTTP dispatch, an
at-least-once event — Restate de-duplicates it to a **single** invocation. (Distinct
dispatches are distinct jobs with distinct uuids, and run independently, as you'd expect.)

## No `queue:work`

There is **no worker to run** against this connection. Restate is the executor: it persists
the invocation, runs the job exactly once, and drives retries itself. Consequently:

- `pop()` returns `null`, and `size()` / `pendingSize()` / `delayedSize()` /
  `reservedSize()` return `0` — the backlog lives in the Restate runtime (observe it through
  Restate's UI/CLI), not in a store this connection can poll. Running `queue:work restate`
  would simply idle.
- **Retry governance moves to Restate.** Because the runtime drives retries, a job's Laravel
  `tries` / `backoff` / `timeout` settings are **not** consulted — the `JobRunner` service's
  Restate invocation retry policy governs instead. An ordinary exception is retried durably;
  throw a `Qcodr\Restate\Sdk\Error\TerminalException` from a job to fail it permanently
  (no retry).

## Errors and durability

The job runs inside `Context::run('handle', …)`:

- On success the (null) step result is journaled; on replay the job's `handle()` is **not**
  re-run — that is the exactly-once guarantee across the invocation's lifecycle.
- An ordinary (non-terminal) exception propagates, so Restate fails the attempt and **retries
  the whole invocation**. A `TerminalException` instead ends the job with a permanent failure.

If the ingress is unreachable at *dispatch* time, Laravel's
`Illuminate\Http\Client\ConnectionException` surfaces from the dispatch call — the same
caller-side failure mode as [dispatch.md](dispatch.md).
