# Use case: faking Restate in a feature test

The [dispatch](dispatch.md) use case starts Restate invocations from ordinary Laravel code via
`Restate::client()`. This one is the **test double** for that caller: `RestateFake` is the
`Bus::fake()` / `Http::fake()` equivalent for Restate, so a feature test can run the code under
test without a real ingress and then assert exactly which invocations it dispatched.

The helper is `Qcodr\Restate\Laravel\Testing\RestateFake`.

> **Why an HTTP-layer fake.** `RestateClient` is `final` and routes every invocation through
> Laravel's HTTP client, so it cannot be subclassed — but it *can* be intercepted where it
> already talks to the wire. `RestateFake::fake()` installs an `Http::fake()` (no real ingress
> is hit; a canned 200 JSON result is returned) and the `assert*` methods translate
> Restate-level questions — "was `OrderWorkflow::run` dispatched?" — into `Http::assertSent(...)`
> truth tests over the exact ingress URL, method, and body the real client produces. Zero
> changes to production code.

## Fake, then assert

```php
use Qcodr\Restate\Laravel\Testing\RestateFake;

public function test_checkout_starts_the_order_workflow(): void
{
    RestateFake::fake();

    $this->postJson('/checkout', ['id' => 1])->assertOk();

    RestateFake::assertSent('OrderWorkflow', 'run', fn (mixed $body, ?string $key): bool
        => $body['id'] === 1 && $key === '1');
}
```

`RestateFake::fake()` resolves the ingress base URL from `restate.ingress.url` (the same URL the
real client is built from); pass an override as the first argument if needed:

```php
RestateFake::fake('http://ingress.test:8080');
```

## Available assertions

| Method | Asserts |
|--------|---------|
| `assertCalled(string $service, string $handler, ?Closure $filter = null)` | a request/response `call()` was dispatched |
| `assertSent(string $service, string $handler, ?Closure $filter = null)` | a one-way `send()` (the `/send` path) was dispatched |
| `assertCalledTimes(string $service, string $handler, int $times, ?Closure $filter = null)` | a `call()` was dispatched exactly `$times` times |
| `assertNothingDispatched()` | no Restate invocation was dispatched (unrelated HTTP is ignored) |

`assertCalled` matches request/response calls and `assertSent` matches one-way sends — a `send()`
will **not** satisfy `assertCalled` and vice versa, mirroring the two distinct ingress paths.

### The `$filter` closure

When given, `$filter` is the final say on whether a dispatch counts. It receives:

1. the **decoded JSON body** — an array (`['id' => 1]`), a bare scalar (`'world'`), or `null` for
   a no-argument call (the real client sends those as an empty body); and
2. the **key** — the Virtual Object / Workflow key, or `null` for an unkeyed Service.

```php
RestateFake::assertCalled('RateLimiterObject', 'hit', fn (mixed $body, ?string $key): bool
    => $body === ['cost' => 1] && $key === 'user-42');
```

A closure may accept just the body if it does not care about the key:

```php
RestateFake::assertSent('PaymentService', 'charge', fn (mixed $body): bool
    => $body['amount'] === 4200);
```

## Programmable result

By default the stub answers every invocation with `{"invocationId": "inv_fake"}`, which is enough
for a faked `send()` to return an invocation id. To control what a faked `call()` returns, pass a
result body:

```php
RestateFake::fake(result: ['greeting' => 'Hello world', 'invocationId' => 'inv_x']);

$greeting = app(RestateClient::class)->call('GreeterService', 'greet', 'world');
// $greeting === ['greeting' => 'Hello world', 'invocationId' => 'inv_x']
```

> A custom result that omits `invocationId` will (correctly) make a faked `send()` throw
> `RestateRequestException`, exactly as a real ingress without that envelope would.

## Facade sugar

The package's `Restate` facade exposes this helper as static sugar, so tests can read
`Restate::fake()` / `Restate::assertCalled(...)` alongside the rest of the API:

```php
use Qcodr\Restate\Laravel\Facades\Restate;

Restate::fake();
// ...
Restate::assertSent('OrderWorkflow', 'run', fn (mixed $body): bool => $body['id'] === 1);
```

Both styles drive the same `RestateFake` underneath; use whichever reads better in your suite.
