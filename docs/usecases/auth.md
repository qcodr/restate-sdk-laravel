# Use case: propagate auth / tenant across a Restate call

A Restate invocation runs **detached** from the HTTP request that started it. There is no
session, no cookie, no middleware stack — so inside a handler Laravel's auth and tenancy state
start empty, even when the caller was fully authenticated. `auth()->user()` is `null`,
`auth()->id()` is `null`, and any tenant your app scopes by is unset.

What *does* survive the hop is **request headers**: the Restate runtime forwards the caller's
request headers to the handler, reachable via the SDK context's `requestHeaders()`. That carries
just enough to re-establish identity — propagate a user id (and a tenant id) on the way in, and
rebind them inside the handler.

This package ships both halves under `Qcodr\Restate\Laravel\Auth`:

| Direction | Class | Job |
|-----------|-------|-----|
| **Inbound** (in a handler) | `RestateContext` | read the propagated id from `ctx->requestHeaders()` and bind it into Laravel for the handler's duration, restoring prior state after |
| **Outbound** (from Laravel code) | `ForwardsAuthHeaders` | turn the *current* user / tenant into the header map a dispatch should carry |

The two use the **same** header names (config below), so a value written outbound is read back
inbound unchanged.

## Configuration

These keys live under `restate.auth.*`. They are read with sensible defaults, so the feature
works with **no config at all** — add keys only to override:

```php
// config/restate.php
'auth' => [
    'user_header'        => env('RESTATE_AUTH_USER_HEADER', 'x-restate-user'),
    'tenant_header'      => env('RESTATE_AUTH_TENANT_HEADER', 'x-restate-tenant'),
    'guard'              => env('RESTATE_AUTH_GUARD'),          // null ⇒ Laravel's default guard
    'tenant_context_key' => 'restate.tenant',                  // Laravel context key the tenant binds under
    'tenant_resolver'    => null,                              // optional callable|invokable class-string
],
```

| Key | Default | Meaning |
|-----|---------|---------|
| `user_header` | `x-restate-user` | request header carrying the propagated user id |
| `tenant_header` | `x-restate-tenant` | request header carrying the propagated tenant id |
| `guard` | `null` (default guard) | which guard to bind the user on; must be a **stateful** (session-style) guard |
| `tenant_context_key` | `restate.tenant` | key the tenant value binds under in Laravel's `Context` |
| `tenant_resolver` | `null` | optional `callable(string $tenantId): mixed` (or invokable class-string) mapping the raw id to the value stored in context — e.g. load a tenant model |

> The header lookup is **case-insensitive**: header names are case-insensitive on the wire
> (RFC 7230 §3.2), and `RestateContext` lower-cases both sides before matching.

## Inbound: rebind identity in a handler

Wrap the handler body in `withAuth()`. Inside the callback, `auth()` and the tenant context
behave exactly as in a normal request; afterwards the prior state is restored — so a worker that
processes many invocations on one booted app never leaks one invocation's identity into the next.

```php
use Qcodr\Restate\Laravel\Auth\RestateContext;
use Qcodr\Restate\Sdk\Attribute\Handler;
use Qcodr\Restate\Sdk\Attribute\Service;
use Qcodr\Restate\Sdk\Context\Context;

#[Service]
final class OrderService
{
    public function __construct(private readonly RestateContext $restate) {}

    #[Handler]
    public function place(Context $ctx, array $payload): array
    {
        // Reads `x-restate-user` / `x-restate-tenant` from the forwarded request headers,
        // resolves the user through the guard's user provider, binds the tenant into context,
        // runs the closure, and restores prior state afterwards.
        return $this->restate->withAuth($ctx, function () use ($payload): array {
            $user = auth()->user();          // the propagated caller — not null
            $tenantId = \Illuminate\Support\Facades\Context::get('restate.tenant');

            // ... ordinary Laravel: policies, tenant-scoped queries, events ...
            return ['placedBy' => auth()->id(), 'tenant' => $tenantId];
        });
    }
}
```

What `withAuth()` does, precisely:

- **User** — if the `user_header` is present, the id is resolved through the configured guard's
  own user provider via `StatefulGuard::onceUsingId()`, which loads the user and sets it on the
  guard **without** touching any session. Absent header ⇒ the handler stays a guest. The prior
  guard user (if any) is captured up front and re-pinned afterwards.
- **Tenant** — if the `tenant_header` is present, the raw id (passed through `tenant_resolver`
  when configured) is stored in Laravel's `Context` under `tenant_context_key`. The prior context
  value is captured and restored afterwards (or the key forgotten if there was none).

`RestateContext` is resolved straight from the container, so constructor-inject it (shown above)
or reach for `app(RestateContext::class)`.

### Mapping a tenant id to a model

Point `tenant_resolver` at a callable to store something richer than the raw id:

```php
'tenant_resolver' => fn (string $id) => \App\Models\Tenant::findOrFail($id),
// then inside the handler:
$tenant = \Illuminate\Support\Facades\Context::get('restate.tenant'); // a Tenant model
```

A resolver may also be an invokable class-string (`__invoke(string $id): mixed`); it is resolved
from the container.

## Outbound: attach identity to a dispatch

`ForwardsAuthHeaders::headers()` turns the *current* request's identity into the header map to
send with an invocation — the user id from the configured guard, and the tenant from Laravel's
context (reduced to a scalar). A guest with no tenant yields an empty array, so nothing is
forwarded that was not actually set.

```php
use Qcodr\Restate\Laravel\Auth\ForwardsAuthHeaders;

$headers = $forwarder->headers();
// ['x-restate-user' => '42', 'x-restate-tenant' => 'acme']
```

> **Wiring assumption — not yet active.** The dispatcher
> `Qcodr\Restate\Laravel\Client\RestateClient` is a `final` class that currently exposes **no
> per-call custom-header parameter**, so these headers cannot yet be attached automatically.
> `ForwardsAuthHeaders` is the ready-to-wire half. Once `RestateClient::call()` / `::send()` gain
> a `?array $headers = null` parameter, the forwarding becomes a one-liner at the call site:
>
> ```php
> $restate->call('OrderService', 'place', $payload, headers: $forwarder->headers());
> ```
>
> Until then `headers()` is fully usable on its own — for a custom dispatcher, a raw call, or a
> test. Only the automatic attachment waits on that parameter.

## The round trip

```
Controller (authenticated user #42, tenant "acme")
   │  ForwardsAuthHeaders::headers()
   ▼
['x-restate-user' => '42', 'x-restate-tenant' => 'acme']   ── attach to dispatch ──┐
                                                                                    ▼
                                          Restate runtime forwards request headers
                                                                                    │
   ┌────────────────────────────────────────────────────────────────────────────── ▼
   ▼  Handler: ctx->requestHeaders()
RestateContext::withAuth($ctx, …)  →  auth()->id() === '42', Context::get('restate.tenant') === 'acme'
   │  (prior state restored when the closure returns)
   ▼
```

## Security note

Propagated headers are only as trustworthy as the path between caller and runtime. The user /
tenant id is taken at face value inside the handler, so treat the Restate ingress as a trusted
boundary: enable request-identity verification (`restate.identity_key`, see the README) so a
handler only accepts invocations signed by your runtime, and never expose the handler route
directly to untrusted clients.
