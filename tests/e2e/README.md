# End-to-end harness

A live integration test for this package: a **real Laravel app** hosting the package's
services against a **real Restate runtime**, driven through the ingress. Unlike the unit
suite (which proves the wiring offline against fakes), this proves the whole path works
against a running runtime over bidirectional HTTP/2.

```bash
make e2e
```

`make e2e` runs [`run.sh`](run.sh), which:

1. `docker compose -f docker-compose.e2e.yml up -d --build` — a `restate` runtime and a
   `laravel-e2e` app built from [`docker/e2e/Dockerfile`](../../docker/e2e/Dockerfile).
2. Waits for the runtime to be healthy (`:9070/health`, `:8080/restate/health`).
3. Registers the deployment over **bidi** — `POST :9070/deployments {"uri":"http://laravel-e2e:9080","force":true}`,
   **no `use_http_11`**, because the amphp host speaks HTTP/2 cleartext (h2c).
4. Drives invocations through the ingress and asserts; then runs a caller-side check.
5. Prints a PASS/FAIL summary and tears the stack down (`KEEP_UP=1` leaves it up).

## The app

[`laravel-app/`](laravel-app/) is a minimal, real Laravel 12 app. It pulls the package via a
Composer **path** repository (`../../..`, symlinked) and the SDK (`qcodr/restate-sdk-php`)
from Packagist. Three services exercise the three durable primitives, all using the SDK's
`?array` input (JsonSerde hands handlers the decoded JSON body, not a hydrated object):

| Class | Kind | Proves |
|-------|------|--------|
| `GreeterService` | `#[Service]` | a stateless handler resolved from the container + served |
| `CounterObject` | `#[VirtualObject]` | per-key durable state, single-writer `add`, concurrent `#[Shared] get` |
| `EchoWorkflow` | `#[Workflow]` | a workflow `run` with a durable `ctx->run()` step |

The endpoint is served by `php artisan restate:serve` (the package's bidi command), with the
in-app HTTP route disabled (config `path => null`).

## What each assertion proves

- **`GreeterService/greet` → `Hello world`** — the package builds the endpoint from config,
  resolves the service through the Laravel container, and serves a `#[Service]` handler that
  the runtime can discover and invoke over bidi.
- **`CounterObject/acme/add` twice → `1` then `2`, `get` → `2`, `globex/add` → `5`** — durable
  Virtual Object state persists across invocations, the single-writer `add` increments
  correctly, the concurrent `#[Shared] get` reads it back, and keys are isolated.
- **`EchoWorkflow/wf-1/run` → `echo:hi`** — a `#[Workflow]` runs to completion including a
  durable `ctx->run()` side effect, returning a journaled result.
- **Caller-side `RestateClient->call(...)` → `Hello ada`** — resolving the package's
  `RestateClient` from the app container and invoking through the ingress works, proving the
  *dispatch* direction (Laravel code → ingress → runtime → handler → result), not just the
  inbound serving direction.

## Runtime note (AVX2, bidi needs >= 1.7)

Bidirectional streaming (the SDK's default transport) needs a Restate runtime **>= 1.7**, and
the runtime **>= 1.6** requires an **AVX2** host (it SIGILLs on older CPUs). The compose stack
defaults to `docker.io/restatedev/restate:1.7.0` and enables the experimental V7 protocol flag
so the amphp host negotiates the SDK's best-tested protocol; the basic call/state/workflow
handlers here also run on the runtime's default V6. Override the image with `RESTATE_IMAGE`,
e.g. `RESTATE_IMAGE=restatedev/restate:latest make e2e`.

This harness is intentionally **outside** the package's unit suite, PHPStan, and Psalm gates —
the Laravel app is its own Composer project. (`make check` is the offline gate; `make e2e` is
the live one.)
