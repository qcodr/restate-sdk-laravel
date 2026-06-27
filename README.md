# Restate SDK for Laravel

[![CI](https://github.com/qcodr/restate-sdk-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/qcodr/restate-sdk-laravel/actions/workflows/ci.yml)
[![PHPStan level max](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](phpstan.neon)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

Laravel integration for the [Restate PHP SDK](https://github.com/qcodr/restate-sdk-php) —
**durable execution** (workflows, virtual objects, sagas, durable timers, exactly-once
side effects) wired into Laravel's container, routing, and Artisan.

Write Restate services as ordinary Laravel classes (with constructor DI), list them in
config, and serve them either from inside your app (a request/response route) or over true
bidirectional HTTP/2 (`php artisan restate:serve`).

## Why

Restate adds guarantees Laravel's native tools don't give you for multi-step, long-running,
or per-entity-stateful work:

| Laravel area | What Restate adds |
|--------------|-------------------|
| **Jobs / multi-step processes** | Durable **sagas** — order → payment → inventory → shipping with automatic retries and compensation, exactly-once |
| **Per-entity state** (counters, balances, inventory, rate limits) | **Virtual Objects** — single-writer state per key, no `lockForUpdate` / row-lock races |
| **Long-running / human-in-the-loop** (approvals, email verify, reminders) | **Durable promises + timers** — wait days for an external event, surviving restarts/deploys |
| **Webhooks / side effects** | Exactly-once processing + the outbox pattern via `ctx->run()` |
| **Scheduler** | Self-rescheduling durable timers that survive restarts (vs stateless cron) |
| **API orchestration / fan-out** | Durable combinators (`select`/`awaitAll`) with retries |

## Requirements

- PHP **8.2+**
- Laravel **11**, **12**, or **13** (Laravel 11 is past security support — composer 2.10+'s
  advisory policy blocks its framework range, so install it with an older composer or an
  advisory override; CI covers it on composer 2.8)
- [`qcodr/restate-sdk-php`](https://github.com/qcodr/restate-sdk-php) (pulled in automatically)
- `amphp/http-server` only if you use `php artisan restate:serve` (bidi)

## Installation

```bash
composer require qcodr/restate-sdk-laravel
php artisan vendor:publish --tag=restate-config   # publishes config/restate.php
```

The service provider is auto-discovered.

## Quick start

Define a service as a normal Laravel class — constructor dependencies are injected from
the container:

```php
namespace App\Restate;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Service\Attribute\{Service, Handler};

#[Service]
final class GreeterService
{
    public function __construct(private readonly \App\Services\Mailer $mailer) {}

    #[Handler]
    public function greet(Context $ctx, string $name): string
    {
        // Non-deterministic work (DB, HTTP, mail) goes inside ctx->run() so it runs
        // exactly once and replays from the journal on retries.
        $ctx->run('notify', fn () => $this->mailer->ping($name));

        return "Hello {$name}";
    }
}
```

Register it in `config/restate.php`:

```php
'services' => [
    App\Restate\GreeterService::class,
],
```

Point a running Restate runtime at your app and invoke through the ingress:

```bash
restate deployments register http://localhost:8000/restate --use-http1.1
curl localhost:8080/GreeterService/greet -H 'content-type: application/json' -d '"world"'
# "Hello world"
```

> **One rule for handlers:** they must be deterministic and stateless. Keep every DB /
> HTTP / mail / random call inside `ctx->run()` (a durable side effect); per-invocation
> data lives in local variables or Restate state, never in instance properties.

## Serving

**In-app route (default).** The provider mounts a catch-all route at the configured prefix
(`restate` by default), served request/response by your normal Laravel stack (FPM, Octane).
Register the deployment at `<app-url>/restate`. Zero extra infrastructure.

**Bidirectional streaming.** For cancellation, signals, and fewer re-invokes on
suspension-heavy handlers, run the amphp host instead:

```bash
composer require amphp/http-server
php artisan restate:serve --port=9080 --workers=8
```

Set config `path` to `null` to disable the in-app route when serving this way.

## Calling Restate from Laravel

The two sections above expose *your* handlers to the runtime. The reverse direction — starting
a Restate invocation from ordinary Laravel code (a controller, job, or listener) — goes through
the Restate **ingress** with the `RestateClient`:

```php
use Qcodr\Restate\Laravel\Client\RestateClient;

public function __construct(private readonly RestateClient $restate) {}

// Call and await the result (request/response):
$greeting = $this->restate->call('GreeterService', 'greet', 'Ada');

// Fire-and-forget — returns the invocation id immediately:
$id = $this->restate->send('OrderWorkflow', 'run', ['orderId' => $orderId], key: $orderId);

// Keyed object/workflow, idempotency, and a durable delayed send:
$this->restate->send('OnboardingService', 'nudge', ['userId' => $id], key: $id, delayMs: 3_600_000);
```

Configure the ingress in `config/restate.php` (`ingress.url`, `RESTATE_INGRESS_URL`; optional
`ingress.token`, `RESTATE_INGRESS_TOKEN` → `Authorization: Bearer`). Full recipe — controllers,
jobs, listeners, idempotency, delayed send, error handling — in
**[docs/usecases/dispatch.md](docs/usecases/dispatch.md)**.

## Configuration

`config/restate.php`:

| Key | Description |
|-----|-------------|
| `services` | Service / virtual-object / workflow class names exposed by the deployment |
| `path` | Route prefix the runtime calls (`null` disables the in-app route) |
| `middleware` | Middleware group for the route (default `['api']`) |
| `identity_key` | `publickeyv1_...` to verify request signatures (needs `ext-sodium`) |
| `ingress.{url,token}` | Restate ingress base URL + optional Bearer token for the `RestateClient` (the caller side) |
| `server.{host,port,workers}` | `restate:serve` bind settings (`workers: 0` = one per CPU) |

## Artisan

```bash
php artisan restate:discover          # list the bound services
php artisan restate:discover --json   # print the raw discovery manifest
php artisan restate:serve             # serve over bidi HTTP/2 (amphp)
```

## Use cases

Worked, tested examples live under `tests/Examples/` (proven offline against the SDK —
discovery, business logic, and the durable flow over a fake context) with copy-pasteable
recipes:

- **[Saga](docs/usecases/saga.md)** — an order-processing workflow (reserve inventory →
  charge → ship) with automatic **compensation** on failure: a thin `#[Workflow]` over
  injected, idempotent services. The proof drives a failing payment and asserts inventory
  is released, shipping never runs, and a `TerminalException` surfaces.
- **[Rate limiter](docs/usecases/rate-limiter.md)** — a per-key token bucket as a
  `#[VirtualObject]`: single-writer state per key, no `lockForUpdate` / Redis race. The
  proof drains one key while another stays full, showing per-key isolation.
- **[Dispatch from Laravel](docs/usecases/dispatch.md)** — the *caller* side: start
  invocations (call-and-await, fire-and-forget `send`, keyed objects/workflows, idempotency,
  durable delayed send) from a controller, job, or listener via the `RestateClient` over the
  Restate ingress.

> Both surface a real SDK boundary: `JsonSerde` hands handlers the **decoded array**, not a
> hydrated object, so a handler's input parameter is `array`/scalar and the value object is
> built (and validated) inside the handler.

## Code quality

Mirrors the SDK's strict gate, all offline:

```bash
make check     # php-cs-fixer + PHPStan (max, with Larastan) + Psalm taint + PHPUnit
```

## License

[Apache-2.0](LICENSE)
