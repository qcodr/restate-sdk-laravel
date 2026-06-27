<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Examples\RateLimiter;

use InvalidArgumentException;

/**
 * A pure, immutable token-bucket rate-limit algorithm — the "fat service" half of the
 * thin-handler/fat-service split.
 *
 * Why a value object with zero SDK coupling:
 *  - ALL the arithmetic (refill, consume, retry-after) lives here, so it is exhaustively
 *    unit-testable by driving `$nowMs` explicitly — no Restate runtime, no Context, no
 *    wall clock.
 *  - Every transition returns a NEW bucket instead of mutating `$this`. Immutability is
 *    what makes the algorithm replay-safe: feeding the same `$nowMs` sequence always
 *    yields the same buckets, so a Restate handler that replays its journal recomputes
 *    an identical result. Hidden in-place mutation is exactly the class of bug that
 *    breaks deterministic replay.
 *
 * The model: a bucket holds up to `$capacity` tokens (the burst ceiling) and refills at a
 * steady rate of `$capacity` tokens per `$refillIntervalMs` (i.e. `capacity/intervalMs`
 * tokens per millisecond). A request that costs N tokens is allowed when at least N are
 * available; otherwise it is denied and told how long until enough have accrued.
 *
 * Time assumption: `$nowMs` is expected to be monotonic non-decreasing (sourced from a
 * sane clock). A backward jump is treated as a safe no-op — no phantom refill, and the
 * internal stamp never moves backwards — so a skewed reading cannot hand out free tokens.
 */
final class TokenBucket
{
    private function __construct(
        public readonly int $capacity,
        public readonly int $refillIntervalMs,
        public readonly float $tokens,
        public readonly int $lastRefillMs,
    ) {
    }

    /**
     * A brand-new, completely full bucket as of `$nowMs` — the state a key starts in
     * before its first request.
     */
    public static function create(int $capacity, int $refillIntervalMs, int $nowMs): self
    {
        self::assertConfig($capacity, $refillIntervalMs);

        return new self($capacity, $refillIntervalMs, (float) $capacity, $nowMs);
    }

    /**
     * Rebuilds a bucket from its persisted form — the map a Restate handler reads back
     * from object state. Tolerant of the JSON round-trip: a token count persisted as the
     * float `4.0` comes back from JSON as the integer `4`, so any numeric `tokens` is
     * accepted and re-normalised (and re-capped at `$capacity`, in case the configured
     * ceiling shrank between deploys).
     *
     * @param array<array-key, mixed> $state the decoded `{tokens, lastRefillMs}` map
     *
     * @throws InvalidArgumentException when the persisted state is structurally corrupt
     */
    public static function fromState(int $capacity, int $refillIntervalMs, array $state): self
    {
        self::assertConfig($capacity, $refillIntervalMs);

        $tokens = $state['tokens'] ?? null;
        $lastRefillMs = $state['lastRefillMs'] ?? null;
        if (!\is_numeric($tokens) || !\is_int($lastRefillMs)) {
            throw new InvalidArgumentException(
                'TokenBucket state must carry a numeric "tokens" and an integer "lastRefillMs".',
            );
        }

        $clamped = \max(0.0, \min((float) $capacity, (float) $tokens));

        return new self($capacity, $refillIntervalMs, $clamped, $lastRefillMs);
    }

    /**
     * Returns a new bucket with the tokens accrued between its last refill and `$nowMs`,
     * capped at capacity. Pure: it never consumes and never mutates `$this`.
     */
    public function refill(int $nowMs): self
    {
        // Never let the stamp drift backwards, so a backward clock reading cannot later be
        // measured as a long elapsed window and over-refill the bucket.
        $stamp = \max($nowMs, $this->lastRefillMs);
        $elapsedMs = $nowMs - $this->lastRefillMs;
        if ($elapsedMs <= 0) {
            return new self($this->capacity, $this->refillIntervalMs, $this->tokens, $stamp);
        }

        // Multiply before dividing so evenly-divisible windows stay exact in float
        // (e.g. 1000ms * 5 / 5000 == 1.0, not 0.999…).
        $accrued = (float) ($elapsedMs * $this->capacity) / $this->refillIntervalMs;
        $tokens = \min((float) $this->capacity, $this->tokens + $accrued);

        return new self($this->capacity, $this->refillIntervalMs, $tokens, $nowMs);
    }

    /**
     * Refills to `$nowMs`, then tries to take `$cost` tokens.
     *
     * On success the returned bucket has the tokens deducted; on denial it is the refilled
     * (but un-deducted) bucket, so the caller still persists the advanced clock and the
     * accrued tokens. `retryAfterMs` is the wait until enough tokens will have accrued to
     * satisfy the request (0 when allowed).
     *
     * @return array{bucket: self, allowed: bool, remaining: int, retryAfterMs: int}
     *
     * @throws InvalidArgumentException when `$cost` is not a positive integer
     */
    public function tryConsume(int $nowMs, int $cost = 1): array
    {
        if ($cost < 1) {
            throw new InvalidArgumentException('Cost must be a positive integer.');
        }

        $refilled = $this->refill($nowMs);

        if ($refilled->tokens >= (float) $cost) {
            $after = new self(
                $refilled->capacity,
                $refilled->refillIntervalMs,
                $refilled->tokens - (float) $cost,
                $refilled->lastRefillMs,
            );

            return [
                'bucket' => $after,
                'allowed' => true,
                'remaining' => $after->remaining(),
                'retryAfterMs' => 0,
            ];
        }

        $deficit = (float) $cost - $refilled->tokens;
        // ms per token == refillIntervalMs / capacity; round up so the caller never retries
        // a millisecond too early.
        $retryAfterMs = (int) \ceil($deficit * $this->refillIntervalMs / $this->capacity);

        return [
            'bucket' => $refilled,
            'allowed' => false,
            'remaining' => $refilled->remaining(),
            'retryAfterMs' => $retryAfterMs,
        ];
    }

    /** Whole tokens currently available (fractional accrual is floored). */
    public function remaining(): int
    {
        return (int) \floor($this->tokens);
    }

    /**
     * The persisted representation a handler writes back via `$ctx->set(...)`. Plain
     * scalars only, so it round-trips cleanly through JSON state.
     *
     * @return array{tokens: float, lastRefillMs: int}
     */
    public function toState(): array
    {
        return ['tokens' => $this->tokens, 'lastRefillMs' => $this->lastRefillMs];
    }

    private static function assertConfig(int $capacity, int $refillIntervalMs): void
    {
        if ($capacity < 1) {
            throw new InvalidArgumentException('Capacity must be a positive integer.');
        }
        if ($refillIntervalMs < 1) {
            throw new InvalidArgumentException('Refill interval (ms) must be a positive integer.');
        }
    }
}
