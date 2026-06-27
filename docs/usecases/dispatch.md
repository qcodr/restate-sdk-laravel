# Use case: dispatch to Restate from Laravel

The other use cases write Restate *handlers*. This one is the **caller**: starting a Restate
invocation from ordinary Laravel code — a controller, a queued job, an event listener — by
talking to the Restate **ingress** over HTTP.

The client is `Qcodr\Restate\Laravel\Client\RestateClient`, a singleton bound by the package
and reachable three ways:

```php
use Qcodr\Restate\Laravel\Client\RestateClient;
use Qcodr\Restate\Laravel\Facades\Restate;

// 1. Constructor injection (preferred — testable, explicit)
public function __construct(private readonly RestateClient $restate) {}

// 2. The facade
Restate::client()->call(/* … */);

// 3. The container
app(RestateClient::class)->call(/* … */);
```

> **Caller, not runtime.** This client has no journal and no replay — it only *starts* work.
> The durability (exactly-once side effects, retries, compensation) lives in the Restate
> service the ingress routes to. Code that itself needs to be durable belongs in a handler;
> this is for kicking handlers off from the rest of your app.

## Configure the ingress

`config/restate.php`:

```php
'ingress' => [
    'url' => env('RESTATE_INGRESS_URL', 'http://localhost:8080'),
    'token' => env('RESTATE_INGRESS_TOKEN'), // optional Bearer token for a secured ingress
],
```

```dotenv
RESTATE_INGRESS_URL=http://localhost:8080
# RESTATE_INGRESS_TOKEN=...   # e.g. a Restate Cloud token
```

The ingress is the **8080** port of a Restate runtime (distinct from the admin/9070 port and
from *this* app's handler route). When `token` is set it is sent as
`Authorization: Bearer <token>` on every request.

## Two ways to invoke

| | `call()` | `send()` |
|--|----------|----------|
| Semantics | request/response — **blocks** for the result | fire-and-forget — returns immediately |
| Returns | the handler's decoded JSON result | the invocation id (`inv_…`) |
| Path | `POST {url}/{Service}/{handler}` | the same path **+ `/send`** |
| Use it for | you need the answer now (a controller response) | background work, fan-out, reminders |

For a keyed target — a **Virtual Object** or **Workflow** — pass `$key`; the client inserts it
as `…/{Service}/{key}/{handler}`. Omit it for a plain **Service**.

### Call and await (controller)

```php
use Qcodr\Restate\Laravel\Client\RestateClient;
use Qcodr\Restate\Laravel\Client\RestateRequestException;

final class GreetController
{
    public function __construct(private readonly RestateClient $restate) {}

    public function __invoke(string $name): \Illuminate\Http\JsonResponse
    {
        try {
            // POST http://localhost:8080/GreeterService/greet   body: "Ada"
            $greeting = $this->restate->call('GreeterService', 'greet', $name);
        } catch (RestateRequestException $e) {
            // Non-2xx from the ingress — $e->status and $e->responseBody carry the detail.
            report($e);

            return response()->json(['error' => 'greeting failed'], 502);
        }

        return response()->json(['greeting' => $greeting]);
    }
}
```

The `$payload` is JSON-encoded as the single handler argument. It can be an array
(`['name' => 'Ada']` → `{"name":"Ada"}`) or a bare scalar (`'Ada'` → `"Ada"`); a handler that
takes no argument is called with `null` (the default), which sends an empty body.

### Keyed target (Virtual Object)

```php
// POST http://localhost:8080/RateLimiterObject/user-42/hit
$verdict = $this->restate->call('RateLimiterObject', 'hit', ['cost' => 1], key: 'user-42');

if ($verdict['allowed'] === false) {
    abort(429);
}
```

### Fire-and-forget (queued job)

`send()` returns as soon as the ingress has accepted the invocation — ideal from a job or a
listener where you do not want to block on the downstream handler:

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Qcodr\Restate\Laravel\Client\RestateClient;

final class StartFulfilmentJob implements ShouldQueue
{
    public function __construct(private readonly string $orderId) {}

    public function handle(RestateClient $restate): void
    {
        // POST http://localhost:8080/OrderWorkflow/{orderId}/run/send
        $invocationId = $restate->send('OrderWorkflow', 'run', ['orderId' => $this->orderId], key: $this->orderId);

        logger()->info('Started Restate fulfilment', ['invocation' => $invocationId]);
    }
}
```

### From an event listener

```php
use Qcodr\Restate\Laravel\Client\RestateClient;

final class NotifyOnSignup
{
    public function __construct(private readonly RestateClient $restate) {}

    public function handle(UserRegistered $event): void
    {
        $this->restate->send('OnboardingService', 'welcome', ['userId' => $event->userId]);
    }
}
```

## Idempotency

Pass an `idempotencyKey` to dedupe retried invocations at the ingress — two requests with the
same key (and target) run the handler **once**; the second observes the first's outcome. It is
sent as the `Idempotency-Key` header. Reach for it whenever a *send* might fire twice (a job
retry, an at-least-once event, a double-clicked button):

```php
$restate->send('PaymentService', 'charge', $payload, idempotencyKey: "charge:{$order->id}");

// On a call, the same key makes the retry return the original result:
$restate->call('PaymentService', 'charge', $payload, idempotencyKey: "charge:{$order->id}");
```

A natural key is one derived from your domain (`"charge:{$orderId}"`), so the *same business
operation* always produces the *same* key.

## Delayed send

`send()` takes an optional `delayMs` — the invocation is scheduled that many **milliseconds**
into the future as a durable, restart-surviving timer on the Restate side. The natural
primitive for reminders, debouncing, or retry-after scheduling:

```php
// Run OnboardingService::nudge for this user in one hour.
$restate->send('OnboardingService', 'nudge', ['userId' => $id], key: $id, delayMs: 3_600_000);
```

> **Assumption flagged.** The Restate ingress accepts the delay as a `delay` query parameter
> in either [humantime](https://docs.restate.dev/services/invocation/http) (`10s`) or ISO-8601
> (`PT10S`) form. This client exposes an integer `delayMs` and serialises it as the
> millisecond humantime form — `?delay=<delayMs>ms` — which is the exact, lossless mapping of
> the argument. (Restate does not currently support a delayed *Workflow* submit from the
> ingress; schedule those from inside another handler.)

## Errors

A non-2xx ingress response (a failed handler, an unknown service/handler, rejected auth)
throws `Qcodr\Restate\Laravel\Client\RestateRequestException`, which carries the raw status and
body so you can branch on them:

```php
use Qcodr\Restate\Laravel\Client\RestateRequestException;

try {
    $restate->call('RateLimiterObject', 'hit', ['cost' => 1], key: 'user-42');
} catch (RestateRequestException $e) {
    $e->status;       // int — e.g. 429
    $e->responseBody; // string — the ingress error envelope, verbatim
}
```

If the ingress is unreachable, Laravel's own `Illuminate\Http\Client\ConnectionException`
surfaces instead — the network is down, not the handler.

## Ingress shape implemented

| Concern | Wire form |
|---------|-----------|
| Call + await (Service) | `POST {url}/{Service}/{handler}` → JSON result |
| Call + await (keyed) | `POST {url}/{Object\|Workflow}/{key}/{handler}` → JSON result |
| One-way send | the same path + `/send` → `{"invocationId":"…","status":"Accepted"}` |
| Delayed send | `?delay=<delayMs>ms` on the send path |
| Idempotency | `Idempotency-Key: <key>` header |
| Auth | `Authorization: Bearer <token>` (when `ingress.token` is set) |
| Body | the payload JSON-encoded (`null` ⇒ empty body) |
