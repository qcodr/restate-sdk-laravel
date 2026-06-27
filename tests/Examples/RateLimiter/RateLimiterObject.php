<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Examples\RateLimiter;

use Qcodr\Restate\Sdk\Context\ObjectContext;
use Qcodr\Restate\Sdk\Context\SharedObjectContext;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Shared;
use Qcodr\Restate\Sdk\Service\Attribute\VirtualObject;

/**
 * A per-key rate limiter as an ordinary Laravel class.
 *
 * This is the headline reason Virtual Objects exist. Restate guarantees that, for any one
 * object key, exclusive (`#[Handler]`) invocations run ONE AT A TIME — a single writer.
 * So the read-modify-write inside {@see hit} (read the bucket, compute the next state,
 * write it back) is serialised by the runtime, per key, with no overlap. That removes the
 * whole problem the classic SQL approach has to solve: no `SELECT ... FOR UPDATE`, no
 * row-lock contention, no lost-update race between two requests hitting the same key.
 * Different keys (`user-42`, `tenant-7`, an IP) get fully independent state and run
 * concurrently.
 *
 * Thin handler, fat service: the handlers here only marshal state in and out of the
 * Context. Every bit of arithmetic lives in the dependency-free {@see TokenBucket}, which
 * is unit-tested in isolation.
 *
 * Determinism / time: a handler must replay deterministically, so it must never read a
 * wall clock directly — `\time()` would return a different value on every replay and
 * corrupt the journal. The Context exposes durable timers but no readable "now", so the
 * current time is supplied as a handler argument (`now`, epoch millis), stamped by the
 * caller or the edge. (The self-sourced alternative is `$ctx->run('now', ...)`, which
 * journals one reading and replays it; passing it in keeps the algorithm trivially
 * testable and the handler free of side effects.)
 */
#[VirtualObject]
final class RateLimiterObject
{
    /** State key under which the per-object {@see TokenBucket} is journaled. */
    private const STATE_KEY = 'bucket';

    private const DEFAULT_CAPACITY = 5;

    private const DEFAULT_REFILL_INTERVAL_MS = 60_000;

    public function __construct(
        private readonly int $capacity = self::DEFAULT_CAPACITY,
        private readonly int $refillIntervalMs = self::DEFAULT_REFILL_INTERVAL_MS,
    ) {
    }

    /**
     * Exclusive (single-writer) handler: account one request against this key's bucket.
     *
     * Because Restate runs exclusive handlers for a key sequentially, the get → compute →
     * set below is atomic with respect to every other `hit`/`reset` on the same key — the
     * race-free heart of the pattern.
     *
     * @param array<array-key, mixed>|null $input the JSON request body:
     *                                             `{"now": <epoch-ms>, "cost"?: <int>=1}`
     *
     * @return array{allowed: bool, remaining: int, retryAfterMs: int}
     */
    #[Handler]
    public function hit(ObjectContext $ctx, ?array $input = null): array
    {
        $payload = $input ?? [];
        $nowMs = $this->readNow($payload);
        $cost = $this->readCost($payload);

        $bucket = $this->load($ctx->get(self::STATE_KEY), $nowMs);
        $decision = $bucket->tryConsume($nowMs, $cost);
        $ctx->set(self::STATE_KEY, $decision['bucket']->toState());

        return [
            'allowed' => $decision['allowed'],
            'remaining' => $decision['remaining'],
            'retryAfterMs' => $decision['retryAfterMs'],
        ];
    }

    /**
     * Exclusive handler: forget this key entirely, so the next {@see hit} starts from a
     * full bucket. Clearing the single state key is all it takes.
     */
    #[Handler]
    public function reset(ObjectContext $ctx): void
    {
        $ctx->clear(self::STATE_KEY);
    }

    /**
     * Shared (read-only, concurrent) handler: report the remaining allowance without
     * touching state. Shared handlers may run concurrently with each other and cannot
     * write — calling `$ctx->set(...)` here would not even type-check, since the parameter
     * is a {@see SharedObjectContext}. It reports the last-journaled allowance as-is (it
     * takes no `now`, so it never advances the clock and never mutates).
     *
     * @return array{remaining: int, capacity: int}
     */
    #[Shared]
    public function peek(SharedObjectContext $ctx): array
    {
        $stored = $ctx->get(self::STATE_KEY);
        $remaining = $stored === null
            ? $this->capacity
            : TokenBucket::fromState($this->capacity, $this->refillIntervalMs, $this->decodeState($stored))->remaining();

        return ['remaining' => $remaining, 'capacity' => $this->capacity];
    }

    /**
     * Rebuilds the bucket from journaled state, or mints a full one on a key's first hit.
     *
     * @param mixed $stored the value {@see ObjectContext::get} returned: null on the first
     *                      invocation for this key, otherwise the decoded state map
     */
    private function load(mixed $stored, int $nowMs): TokenBucket
    {
        if ($stored === null) {
            return TokenBucket::create($this->capacity, $this->refillIntervalMs, $nowMs);
        }

        return TokenBucket::fromState($this->capacity, $this->refillIntervalMs, $this->decodeState($stored));
    }

    /**
     * Narrows the untyped value read back from state to the expected map shape, failing
     * with a terminal (non-retryable) error if the journal holds something unexpected —
     * a corrupt bucket will never become valid by retrying.
     *
     * @return array<array-key, mixed>
     */
    private function decodeState(mixed $stored): array
    {
        if (!\is_array($stored)) {
            throw new TerminalException('Corrupt rate-limiter state: expected a JSON object.', 500);
        }

        return $stored;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function readNow(array $payload): int
    {
        $now = $payload['now'] ?? null;
        if (!\is_int($now) || $now < 0) {
            throw new TerminalException(
                "The 'hit' handler requires a non-negative integer 'now' (epoch milliseconds).",
                400,
            );
        }

        return $now;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function readCost(array $payload): int
    {
        $cost = $payload['cost'] ?? 1;
        if (!\is_int($cost) || $cost < 1) {
            throw new TerminalException("The 'hit' handler 'cost' must be a positive integer.", 400);
        }

        return $cost;
    }
}
