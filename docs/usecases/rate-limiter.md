# Use case: per-key rate limiter (Virtual Object)

A rate limiter is the textbook case for a Restate **Virtual Object**: state that belongs to
*one key* (a user, a tenant, an API token, an IP) and must be updated without two requests
stepping on each other. Restate runs a Virtual Object's exclusive handlers **one at a time
per key**, so the read-modify-write is serialised for you — no row locks, no races.

The runnable, fully-tested version of everything below lives in
[`tests/Examples/RateLimiter/`](../../tests/Examples/RateLimiter) with tests in
[`tests/Unit/Examples/RateLimiter/`](../../tests/Unit/Examples/RateLimiter).

## When to use this (vs the alternatives)

| Approach | The catch |
|----------|-----------|
| `SELECT … FOR UPDATE` / `lockForUpdate()` on a `rate_limits` row | A held row lock per request: contention, lock-wait timeouts, and deadlocks under load. You are hand-rolling the single-writer guarantee the DB only gives you while the transaction is open. |
| `INSERT … ON DUPLICATE KEY UPDATE` counter | Easy to get a lost update between read and write; window/TTL bookkeeping is fiddly; still a hot row per key. |
| Redis `INCR` + `EXPIRE` (or a Lua script) | Works well, but it is a second piece of infrastructure to run, secure, and keep consistent with your DB, and the atomicity lives in a Lua blob, not your domain code. |
| **Restate Virtual Object (this)** | Single-writer **per key** is the runtime's contract. The limiter is an ordinary, unit-testable PHP class; state is durable and survives restarts/deploys; different keys scale out concurrently. |

Reach for it when you want the limiter to be **plain testable code** with **no extra
datastore** and **no lock choreography**, and you are already (or willing to be) running a
Restate runtime in front of the work.

## The algorithm (pure, immutable, no SDK coupling)

All the math is a dependency-free value object — a token bucket that refills at a steady
rate up to a burst ceiling. It never mutates; every transition returns a new bucket, which
is what keeps it replay-safe and trivially unit-testable.

```php
namespace App\Restate\RateLimiter;

use InvalidArgumentException;

final class TokenBucket
{
    private function __construct(
        public readonly int $capacity,
        public readonly int $refillIntervalMs,
        public readonly float $tokens,
        public readonly int $lastRefillMs,
    ) {
    }

    public static function create(int $capacity, int $refillIntervalMs, int $nowMs): self
    {
        self::assertConfig($capacity, $refillIntervalMs);

        return new self($capacity, $refillIntervalMs, (float) $capacity, $nowMs);
    }

    /** @param array<array-key, mixed> $state the decoded {tokens, lastRefillMs} map */
    public static function fromState(int $capacity, int $refillIntervalMs, array $state): self
    {
        self::assertConfig($capacity, $refillIntervalMs);

        $tokens = $state['tokens'] ?? null;
        $lastRefillMs = $state['lastRefillMs'] ?? null;
        if (!\is_numeric($tokens) || !\is_int($lastRefillMs)) {
            throw new InvalidArgumentException('Corrupt TokenBucket state.');
        }

        return new self($capacity, $refillIntervalMs, \max(0.0, \min((float) $capacity, (float) $tokens)), $lastRefillMs);
    }

    public function refill(int $nowMs): self
    {
        $stamp = \max($nowMs, $this->lastRefillMs);          // never drift backwards
        $elapsedMs = $nowMs - $this->lastRefillMs;
        if ($elapsedMs <= 0) {
            return new self($this->capacity, $this->refillIntervalMs, $this->tokens, $stamp);
        }

        $accrued = (float) ($elapsedMs * $this->capacity) / $this->refillIntervalMs;
        $tokens = \min((float) $this->capacity, $this->tokens + $accrued);

        return new self($this->capacity, $this->refillIntervalMs, $tokens, $nowMs);
    }

    /** @return array{bucket: self, allowed: bool, remaining: int, retryAfterMs: int} */
    public function tryConsume(int $nowMs, int $cost = 1): array
    {
        if ($cost < 1) {
            throw new InvalidArgumentException('Cost must be a positive integer.');
        }

        $refilled = $this->refill($nowMs);
        if ($refilled->tokens >= (float) $cost) {
            $after = new self($refilled->capacity, $refilled->refillIntervalMs, $refilled->tokens - (float) $cost, $refilled->lastRefillMs);

            return ['bucket' => $after, 'allowed' => true, 'remaining' => $after->remaining(), 'retryAfterMs' => 0];
        }

        $deficit = (float) $cost - $refilled->tokens;
        $retryAfterMs = (int) \ceil($deficit * $this->refillIntervalMs / $this->capacity);

        return ['bucket' => $refilled, 'allowed' => false, 'remaining' => $refilled->remaining(), 'retryAfterMs' => $retryAfterMs];
    }

    public function remaining(): int
    {
        return (int) \floor($this->tokens);
    }

    /** @return array{tokens: float, lastRefillMs: int} */
    public function toState(): array
    {
        return ['tokens' => $this->tokens, 'lastRefillMs' => $this->lastRefillMs];
    }

    private static function assertConfig(int $capacity, int $refillIntervalMs): void
    {
        if ($capacity < 1 || $refillIntervalMs < 1) {
            throw new InvalidArgumentException('Capacity and refill interval must be positive.');
        }
    }
}
```

## The Virtual Object (thin handlers)

The handlers only marshal state in and out of the Context — the "thin handler, fat
service" rule. Note the time handling: a handler must replay deterministically, so it must
**never** read a wall clock directly (`\time()` would differ on every replay and corrupt
the journal). The Context exposes durable timers but no readable "now", so the current time
arrives as a handler argument (stamped by the caller/edge). The self-sourced alternative is
`$ctx->run('now', fn () => …)`, which journals one reading and replays it.

```php
namespace App\Restate\RateLimiter;

use Qcodr\Restate\Sdk\Context\ObjectContext;
use Qcodr\Restate\Sdk\Context\SharedObjectContext;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Shared;
use Qcodr\Restate\Sdk\Service\Attribute\VirtualObject;

#[VirtualObject]
final class RateLimiterObject
{
    private const STATE_KEY = 'bucket';

    public function __construct(
        private readonly int $capacity = 5,
        private readonly int $refillIntervalMs = 60_000,
    ) {
    }

    /**
     * Exclusive (single-writer) handler. Restate serialises this per object key, so the
     * get → compute → set below is atomic against every other hit/reset on the same key.
     *
     * @param array<array-key, mixed>|null $input {"now": <epoch-ms>, "cost"?: <int>=1}
     * @return array{allowed: bool, remaining: int, retryAfterMs: int}
     */
    #[Handler]
    public function hit(ObjectContext $ctx, ?array $input = null): array
    {
        $payload = $input ?? [];
        $nowMs = $this->readNow($payload);
        $cost = $this->readCost($payload);

        $stored = $ctx->get(self::STATE_KEY);                       // null on first hit
        $bucket = $stored === null
            ? TokenBucket::create($this->capacity, $this->refillIntervalMs, $nowMs)
            : TokenBucket::fromState($this->capacity, $this->refillIntervalMs, $this->decode($stored));

        $decision = $bucket->tryConsume($nowMs, $cost);
        $ctx->set(self::STATE_KEY, $decision['bucket']->toState());

        return [
            'allowed' => $decision['allowed'],
            'remaining' => $decision['remaining'],
            'retryAfterMs' => $decision['retryAfterMs'],
        ];
    }

    /** Exclusive handler: forget the key so the next hit starts from a full bucket. */
    #[Handler]
    public function reset(ObjectContext $ctx): void
    {
        $ctx->clear(self::STATE_KEY);
    }

    /**
     * Shared (read-only, concurrent) handler: report the allowance without mutating.
     * Calling $ctx->set() here would not even type-check — the parameter is a
     * SharedObjectContext.
     *
     * @return array{remaining: int, capacity: int}
     */
    #[Shared]
    public function peek(SharedObjectContext $ctx): array
    {
        $stored = $ctx->get(self::STATE_KEY);
        $remaining = $stored === null
            ? $this->capacity
            : TokenBucket::fromState($this->capacity, $this->refillIntervalMs, $this->decode($stored))->remaining();

        return ['remaining' => $remaining, 'capacity' => $this->capacity];
    }

    /** @return array<array-key, mixed> */
    private function decode(mixed $stored): array
    {
        if (!\is_array($stored)) {
            throw new TerminalException('Corrupt rate-limiter state.', 500);
        }

        return $stored;
    }

    /** @param array<array-key, mixed> $payload */
    private function readNow(array $payload): int
    {
        $now = $payload['now'] ?? null;
        if (!\is_int($now) || $now < 0) {
            throw new TerminalException("'hit' requires a non-negative integer 'now' (epoch ms).", 400);
        }

        return $now;
    }

    /** @param array<array-key, mixed> $payload */
    private function readCost(array $payload): int
    {
        $cost = $payload['cost'] ?? 1;
        if (!\is_int($cost) || $cost < 1) {
            throw new TerminalException("'cost' must be a positive integer.", 400);
        }

        return $cost;
    }
}
```

> Constructor arguments (`capacity`, `refillIntervalMs`) are resolved by Laravel's
> container, so you can bind them from config — e.g. `$this->app->when(RateLimiterObject::class)`
> or a small factory — to make the policy configurable per environment.

## Register it

In `config/restate.php`:

```php
'services' => [
    App\Restate\RateLimiter\RateLimiterObject::class,
],
```

Then expose the deployment and point a running Restate runtime at it (see the project
[README](../../README.md) — the in-app route, or `php artisan restate:serve` for bidi):

```bash
restate deployments register http://localhost:8000/restate --use-http1.1
```

## Invoke it — per key

A Virtual Object is addressed as `/{Object}/{key}/{handler}` through the Restate ingress.
The `key` segment is the limiter identity (user id, tenant, API token, IP). Each distinct
key has its own isolated bucket.

```bash
# Account one request for user 42 (the edge stamps the current time in millis):
curl localhost:8080/RateLimiterObject/user-42/hit \
  -H 'content-type: application/json' \
  -d '{"now": 1717000000000, "cost": 1}'
# {"allowed":true,"remaining":4,"retryAfterMs":0}

# Over the limit → denied with a hint of how long to back off:
# {"allowed":false,"remaining":0,"retryAfterMs":12000}

# Peek without spending a token (shared, read-only):
curl localhost:8080/RateLimiterObject/user-42/peek

# Clear a key's allowance:
curl localhost:8080/RateLimiterObject/user-42/reset -X POST
```

From another Restate handler you would call it durably instead of over HTTP:

```php
$verdict = $ctx->objectCall('RateLimiterObject', $userId, 'hit', ['now' => $nowMs]);
if ($verdict['allowed'] === false) {
    throw new TerminalException('Rate limit exceeded.', 429);
}
```

## Why single-writer-per-key removes the race

With a SQL counter, two concurrent requests for the same user can both read "4 remaining",
both decide "allowed", and both write "3" — a classic lost update. You avoid it by holding
a row lock for the duration of the transaction, which is exactly the contention you were
trying to escape.

Restate gives the guarantee at a different layer: for a single object **key**, exclusive
handlers are dispatched **sequentially** — the next `hit` for `user-42` does not start until
the previous one has committed its state. So the `get → tryConsume → set` sequence is
effectively atomic *without any locking in your code*, and it is durable (it survives
worker restarts and deploys). Meanwhile `user-43`, `tenant-7`, and every other key run
fully in parallel, so there is no global hot row and no cross-key contention. The limiter
stays an ordinary class you can unit-test in milliseconds — which is exactly what the
accompanying tests do, including a case that drains one key and shows a second key is
completely unaffected.
