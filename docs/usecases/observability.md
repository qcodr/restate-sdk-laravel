# Use case: observability (logging + Telescope)

Restate handlers run *inside* the durable runtime, replaying from the top on every slice. This
page is about making that execution observable from Laravel:

1. **Logging** — a handler's `ctx->logger()->info(...)` should land in your application's log
   channels, with framework formatting and handlers, and **exactly once** despite replay.
2. **Telescope** — each Restate ingress dispatch (a `RestateClient::call()` / `send()`) should be
   visible and **filterable by Restate identity** (service, handler, key, call style) in
   Laravel Telescope, when Telescope is installed.

Both are wired by a single provider, `RestateObservabilityServiceProvider`, and neither requires
Telescope to be present.

```php
use Qcodr\Restate\Laravel\Logging\RestateLogger;
use Qcodr\Restate\Laravel\Logging\RestateObservabilityServiceProvider;
use Qcodr\Restate\Laravel\Telescope\RestateWatcher;
```

---

## Logging: handler logs into Laravel's stack, replay-aware

### How it flows

The SDK already solves the *replay* problem. `RestateContext::logger()` wraps whatever PSR-3
logger the endpoint was built with in a
[`ReplayAwareLogger`](../../vendor/qcodr/restate-sdk-php/src/Context/ReplayAwareLogger.php) that
**drops records emitted while the invocation is replaying** and forwards only those emitted during
real processing. So a handler line ships once, on the slice that actually executed it — not again
on every replay.

What was missing is the *underlying* logger: by default the SDK uses a `NullLogger`, so handler
logs go nowhere. This package supplies **Laravel's logger** as that underlying logger:

```
handler:  ctx->logger()->info('charged', ['order' => $id])
            │
            ▼
SDK:      ReplayAwareLogger      ── suppressed while replaying ──► (dropped)
            │  (processing only)
            ▼
package:  RestateLogger          ── selects the Laravel channel ──►
            │
            ▼
Laravel:  Log::channel(...)      ──► your handlers / formatters / files / stderr
```

`RestateLogger` is a thin PSR-3 accessor over a Laravel log channel. It does **not** re-implement
replay filtering — that stays the SDK's job — it only chooses *where* records go and resolves the
channel lazily (per write), so test doubles such as `Log::spy()` / `Log::listen()` and live channel
reconfiguration are honoured.

### Configuration

Zero-config: with no extra settings, handler logs go to your **default** log stack
(`config/logging.php`'s `default`).

To route Restate handler output to a **dedicated channel**, define one in `config/logging.php` and
point the package at it from `config/restate.php`:

```php
// config/logging.php
'channels' => [
    'restate' => [
        'driver' => 'daily',
        'path' => storage_path('logs/restate.log'),
        'level' => env('RESTATE_LOG_LEVEL', 'info'),
        'days' => 14,
    ],
],

// config/restate.php
'logging' => [
    'channel' => env('RESTATE_LOG_CHANNEL', 'restate'),
],
```

When `restate.logging.channel` is unset (the default — the shipped config does not declare it), the
default stack is used.

### What you get

- Handler logs appear in your channels with the level, message and context the handler passed.
- Replays do **not** duplicate lines — each is emitted exactly once.
- Per-invocation context (e.g. `['order' => $id]`) rides along as Monolog context.

---

## Telescope: Restate-tagged ingress dispatches

`RestateClient` dispatches over Laravel's HTTP client (`Illuminate\Http\Client\Factory`). Telescope
already records every outgoing HTTP call through its built-in **Client Request watcher** — so the
method, URI, payload, response and duration of each Restate dispatch are *already* in Telescope.

What a raw HTTP entry lacks is **Restate identity**: from a bare URI you cannot filter Telescope to
"every call to the `Orders` workflow" or "every send to object key `tenant-7`".
`RestateWatcher` closes that gap. It registers a Telescope tag callback that recognises Restate
ingress URIs and attaches tags:

| Tag | Meaning |
|-----|---------|
| `restate` | any Restate ingress dispatch |
| `restate:type:call` / `restate:type:send` | blocking call vs fire-and-forget send |
| `restate:service:<Name>` | the service / object / workflow name |
| `restate:handler:<name>` | the handler method |
| `restate:key:<key>` | the object / workflow instance key (keyed dispatches only) |

In Telescope's HTTP Client tab you can then filter by any of these tags to isolate a single Restate
service — or even a single object instance — among all outgoing HTTP traffic.

### Telescope is optional

The package does **not** depend on Telescope. `laravel/telescope` belongs in composer `suggest`,
and every reference to Telescope is guarded by a string-literal `class_exists()` check (never a
`::class` constant, which would require the class to be loadable). When Telescope is absent the
watcher registration is a silent no-op and nothing in the package needs the Telescope classes —
including static analysis.

Install Telescope as usual and the watcher activates automatically — no `telescope.watchers` config
entry is required (the package registers the tag callback itself; do not also list `RestateWatcher`
in `telescope.watchers`, or dispatches would be tagged twice).

---

## Wiring (for the package maintainer)

The observability classes live under `src/Logging/` and `src/Telescope/`. Two one-line hooks
activate them.

### 1. Register the provider

In `RestateServiceProvider::register()`, alongside the existing sub-providers:

```php
$this->app->register(\Qcodr\Restate\Laravel\Logging\RestateObservabilityServiceProvider::class);
```

This binds `RestateLogger` and, when Telescope is present, registers the dispatch-tagging watcher
on boot.

### 2. Feed Laravel's logger to the SDK

The binding is inert until the logger is threaded into the SDK's endpoint construction. The SDK
wraps it in its replay-aware decorator automatically.

**In-app request/response route** — `RestateManager::processor()`:

```php
use Qcodr\Restate\Laravel\Logging\RestateLogger;

public function processor(): RequestProcessor
{
    return new RequestProcessor(
        $this->endpoint(),
        logger: $this->container->make(RestateLogger::class),
    );
}
```

**Standalone bidi server** — `ServeCommand::handle()` (the `restate:serve` host):

```php
use Qcodr\Restate\Laravel\Logging\RestateLogger;

(new AmpStreamingServer(
    $manager->endpoint(),
    logger: app(RestateLogger::class),
))->listen($host, $port, $workers);
```

> A caller who only wants the plain default channel (no `restate` channel selection) can pass
> `app(\Psr\Log\LoggerInterface::class)` instead — Laravel already aliases that contract to its
> `LogManager`. The package deliberately does **not** rebind `LoggerInterface`, so it never hijacks
> framework-wide PSR-3 injection; it ships its own `RestateLogger` key instead.

### 3. composer.json

```jsonc
"suggest": {
    "laravel/telescope": "Surfaces Restate ingress dispatches (service/handler/key/type tags) in Telescope"
},
"require-dev": {
    "laravel/telescope": "^5.0"   // only to exercise the Telescope path in CI; runtime stays optional
}
```

`laravel/telescope` is a `suggest`, not a `require`. The `require-dev` entry is optional and only
needed if you want CI to run the watcher against the real Telescope classes; the unit tests here
cover the watcher's shaping logic without it.

---

## Testing notes

- The watcher's record-shaping (`RestateDispatch` parsing + `RestateWatcher::tagsForEntry()`) is
  pure and unit-tested with plain arrays — no Telescope, no container.
- The logger path is tested two ways: `RestateLogger` forwarding in isolation (with a recording
  PSR-3 double), and end-to-end under Testbench (a record reaches Laravel's log stack exactly once;
  wrapped in the SDK's `ReplayAwareLogger`, replay output is suppressed; an opt-in `restate` channel
  is honoured).
- With Telescope absent, the provider boots cleanly and `RestateWatcher::register()` is a guarded
  no-op — both are asserted.
