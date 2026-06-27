<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Examples\RateLimiter;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Laravel\Tests\Examples\RateLimiter\TokenBucket;

/**
 * Pure-algorithm tests for {@see TokenBucket}.
 *
 * Every case drives `$nowMs` explicitly, so the math is verified with zero dependence on
 * a real clock or the Restate runtime. The fixtures use a capacity of 5 over a 5000ms
 * window — a clean rate of exactly 1 token per 1000ms — so refill amounts are exact in
 * float and the assertions read directly.
 */
final class TokenBucketTest extends TestCase
{
    private const CAPACITY = 5;

    private const INTERVAL_MS = 5_000; // 1 token / 1000ms

    public function testNewBucketStartsFull(): void
    {
        $bucket = TokenBucket::create(self::CAPACITY, self::INTERVAL_MS, 1_000);

        self::assertSame(self::CAPACITY, $bucket->remaining());
    }

    public function testConsumesDownToCapacityThenDenies(): void
    {
        // Arrange: a full bucket, all requests at the same instant so no refill interferes.
        $bucket = TokenBucket::create(self::CAPACITY, self::INTERVAL_MS, 0);

        // Act: the first CAPACITY requests are allowed, draining the bucket to empty.
        $allowed = 0;
        for ($i = 0; $i < self::CAPACITY; ++$i) {
            $decision = $bucket->tryConsume(0, 1);
            self::assertTrue($decision['allowed'], "request {$i} should be allowed");
            $bucket = $decision['bucket'];
            ++$allowed;
        }

        // Assert: exactly CAPACITY allowed, and the next one over the limit is denied.
        self::assertSame(self::CAPACITY, $allowed);
        self::assertSame(0, $bucket->remaining());

        $over = $bucket->tryConsume(0, 1);
        self::assertFalse($over['allowed']);
        self::assertSame(0, $over['remaining']);
    }

    public function testConsumingExactlyAllAvailableIsAllowed(): void
    {
        $bucket = TokenBucket::create(self::CAPACITY, self::INTERVAL_MS, 0);

        $decision = $bucket->tryConsume(0, self::CAPACITY);

        self::assertTrue($decision['allowed']);
        self::assertSame(0, $decision['remaining']);
        self::assertSame(0, $decision['retryAfterMs']);
    }

    public function testRefillIsProportionalToElapsedTime(): void
    {
        // Drain the bucket at t=0.
        $bucket = TokenBucket::create(self::CAPACITY, self::INTERVAL_MS, 0)
            ->tryConsume(0, self::CAPACITY)['bucket'];
        self::assertSame(0, $bucket->remaining());

        // 2000ms later, at 1 token/1000ms, two tokens have accrued.
        $refilled = $bucket->refill(2_000);

        self::assertSame(2, $refilled->remaining());
    }

    public function testRefillNeverExceedsCapacity(): void
    {
        $bucket = TokenBucket::create(self::CAPACITY, self::INTERVAL_MS, 0)
            ->tryConsume(0, self::CAPACITY)['bucket'];

        // A very long idle period must not overflow the burst ceiling.
        $refilled = $bucket->refill(1_000_000);

        self::assertSame(self::CAPACITY, $refilled->remaining());
    }

    public function testDeniedRequestReportsRetryAfter(): void
    {
        $bucket = TokenBucket::create(self::CAPACITY, self::INTERVAL_MS, 0)
            ->tryConsume(0, self::CAPACITY)['bucket'];

        // Empty bucket, ask for one token: need 1 token, accruing at 1000ms/token.
        $decision = $bucket->tryConsume(0, 1);

        self::assertFalse($decision['allowed']);
        self::assertSame(0, $decision['remaining']);
        self::assertSame(1_000, $decision['retryAfterMs']);
    }

    public function testRetryAfterAccountsForPartiallyAccruedTokens(): void
    {
        $bucket = TokenBucket::create(self::CAPACITY, self::INTERVAL_MS, 0)
            ->tryConsume(0, self::CAPACITY)['bucket'];

        // 500ms after draining, half a token has accrued; a cost of 1 is still denied and
        // the deficit (0.5 token) needs another 500ms.
        $decision = $bucket->tryConsume(500, 1);

        self::assertFalse($decision['allowed']);
        self::assertSame(0, $decision['remaining']); // floor(0.5) == 0
        self::assertSame(500, $decision['retryAfterMs']);
    }

    public function testCostGreaterThanOneConsumesAndDeniesCorrectly(): void
    {
        $bucket = TokenBucket::create(self::CAPACITY, self::INTERVAL_MS, 0);

        $first = $bucket->tryConsume(0, 3);
        self::assertTrue($first['allowed']);
        self::assertSame(2, $first['remaining']);

        // Only 2 tokens left, a cost of 3 is denied; deficit is 1 token => 1000ms.
        $second = $first['bucket']->tryConsume(0, 3);
        self::assertFalse($second['allowed']);
        self::assertSame(2, $second['remaining']);
        self::assertSame(1_000, $second['retryAfterMs']);
    }

    public function testBackwardClockIsASafeNoOp(): void
    {
        // Drain at t=1000.
        $bucket = TokenBucket::create(self::CAPACITY, self::INTERVAL_MS, 1_000)
            ->tryConsume(1_000, self::CAPACITY)['bucket'];

        // A reading from the past must not refill, and must not move the internal stamp
        // backwards: only 500ms of *real* progress (1000 -> 1500) has happened, so a cost
        // of 1 is still denied. (If the stamp had drifted back to 500, this would wrongly
        // measure 1000ms elapsed and allow it.)
        $rewound = $bucket->refill(500);
        $next = $rewound->tryConsume(1_500, 1);

        self::assertFalse($next['allowed']);
    }

    public function testStateRoundTripSurvivesJsonNumberCoercion(): void
    {
        // After one consume the bucket holds 4.0 tokens; toState() emits a float.
        $bucket = TokenBucket::create(self::CAPACITY, self::INTERVAL_MS, 0)
            ->tryConsume(0, 1)['bucket'];

        // JSON turns 4.0 into the integer 4 on the way back — exactly what object state does.
        $encoded = \json_encode($bucket->toState(), JSON_THROW_ON_ERROR);
        $decoded = \json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $restored = TokenBucket::fromState(self::CAPACITY, self::INTERVAL_MS, $decoded);

        self::assertSame(4, $restored->remaining());
    }

    public function testFromStateReCapsTokensWhenCapacityShrank(): void
    {
        // State persisted under a larger capacity must be capped to the current ceiling.
        $restored = TokenBucket::fromState(self::CAPACITY, self::INTERVAL_MS, [
            'tokens' => 9999,
            'lastRefillMs' => 0,
        ]);

        self::assertSame(self::CAPACITY, $restored->remaining());
    }

    public function testCreateRejectsNonPositiveCapacity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TokenBucket::create(0, self::INTERVAL_MS, 0);
    }

    public function testCreateRejectsNonPositiveInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TokenBucket::create(self::CAPACITY, 0, 0);
    }

    public function testTryConsumeRejectsNonPositiveCost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TokenBucket::create(self::CAPACITY, self::INTERVAL_MS, 0)->tryConsume(0, 0);
    }

    public function testFromStateRejectsCorruptTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TokenBucket::fromState(self::CAPACITY, self::INTERVAL_MS, [
            'tokens' => 'not-a-number',
            'lastRefillMs' => 0,
        ]);
    }

    public function testFromStateRejectsMissingStamp(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TokenBucket::fromState(self::CAPACITY, self::INTERVAL_MS, ['tokens' => 1.0]);
    }
}
