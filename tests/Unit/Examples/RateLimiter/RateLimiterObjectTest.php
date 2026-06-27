<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Examples\RateLimiter;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Laravel\Tests\Examples\RateLimiter\FakeObjectContext;
use Qcodr\Restate\Laravel\Tests\Examples\RateLimiter\RateLimiterObject;
use Qcodr\Restate\Sdk\Error\TerminalException;

/**
 * The end-to-end proof: drive the {@see RateLimiterObject} handlers over an in-memory
 * {@see FakeObjectContext} and assert the per-key single-writer state flow.
 *
 * Because {@see ObjectContext}/{@see SharedObjectContext} are interfaces, the fake is a
 * faithful stand-in (state serialised on `set`, deserialised on `get`, missing key reads
 * as `null`), so these tests exercise the real read-modify-write path the runtime would —
 * just without a runtime. The single-writer guarantee itself is provided by Restate at
 * deploy time; what we prove here is that the handler logic riding on top is correct:
 * allow-up-to-limit, deny-over, refill-over-time, peek-without-mutation, reset-clears, and
 * — the crux of "no races" — that two keys keep fully independent state.
 *
 * Fixture: capacity 3 over a 3000ms window (a clean 1 token / 1000ms).
 */
final class RateLimiterObjectTest extends TestCase
{
    private const CAPACITY = 3;

    private const INTERVAL_MS = 3_000; // 1 token / 1000ms

    private function limiter(): RateLimiterObject
    {
        return new RateLimiterObject(self::CAPACITY, self::INTERVAL_MS);
    }

    public function testHitAllowsUpToCapacityThenDenies(): void
    {
        $limiter = $this->limiter();
        $ctx = new FakeObjectContext('user-42');
        $now = 1_000;

        $first = $limiter->hit($ctx, ['now' => $now]);
        $second = $limiter->hit($ctx, ['now' => $now]);
        $third = $limiter->hit($ctx, ['now' => $now]);
        $fourth = $limiter->hit($ctx, ['now' => $now]);

        self::assertSame(['allowed' => true, 'remaining' => 2, 'retryAfterMs' => 0], $first);
        self::assertSame(['allowed' => true, 'remaining' => 1, 'retryAfterMs' => 0], $second);
        self::assertSame(['allowed' => true, 'remaining' => 0, 'retryAfterMs' => 0], $third);
        self::assertSame(['allowed' => false, 'remaining' => 0, 'retryAfterMs' => 1_000], $fourth);
    }

    public function testEveryHitPersistsStateUnderTheObjectKey(): void
    {
        $limiter = $this->limiter();
        $ctx = new FakeObjectContext('user-42');

        $limiter->hit($ctx, ['now' => 0]);
        $limiter->hit($ctx, ['now' => 0]);

        // Each exclusive hit performs exactly one durable write (the read-modify-write the
        // runtime serialises per key), and the bucket lives under the object's state key.
        self::assertSame(2, $ctx->writeCount());
        self::assertTrue($ctx->has('bucket'));
        self::assertArrayHasKey('bucket', $ctx->snapshot());
    }

    public function testStateIsIsolatedPerKey(): void
    {
        $limiter = $this->limiter();
        // Two distinct keys == two distinct object instances == two independent buckets.
        $tenantA = new FakeObjectContext('tenant-a');
        $tenantB = new FakeObjectContext('tenant-b');

        // Drain tenant A completely.
        $limiter->hit($tenantA, ['now' => 0]);
        $limiter->hit($tenantA, ['now' => 0]);
        $limiter->hit($tenantA, ['now' => 0]);
        $deniedA = $limiter->hit($tenantA, ['now' => 0]);

        // Tenant B is untouched and still has its full allowance — no shared global counter,
        // so no cross-key contention or races.
        $allowedB = $limiter->hit($tenantB, ['now' => 0]);

        self::assertFalse($deniedA['allowed']);
        self::assertTrue($allowedB['allowed']);
        self::assertSame(self::CAPACITY - 1, $allowedB['remaining']);
    }

    public function testRefillAllowsRequestsAgainAfterTheWindow(): void
    {
        $limiter = $this->limiter();
        $ctx = new FakeObjectContext();

        $limiter->hit($ctx, ['now' => 0]);
        $limiter->hit($ctx, ['now' => 0]);
        $limiter->hit($ctx, ['now' => 0]);
        self::assertFalse($limiter->hit($ctx, ['now' => 0])['allowed']);

        // 1000ms later one token has accrued, so a request is allowed again.
        $afterWait = $limiter->hit($ctx, ['now' => 1_000]);

        self::assertTrue($afterWait['allowed']);
        self::assertSame(0, $afterWait['remaining']);
    }

    public function testCostConsumesMultipleTokens(): void
    {
        $limiter = new RateLimiterObject(5, 5_000); // 1 token / 1000ms
        $ctx = new FakeObjectContext();

        $bulk = $limiter->hit($ctx, ['now' => 0, 'cost' => 3]);
        self::assertSame(['allowed' => true, 'remaining' => 2, 'retryAfterMs' => 0], $bulk);

        $tooMuch = $limiter->hit($ctx, ['now' => 0, 'cost' => 3]);
        self::assertSame(['allowed' => false, 'remaining' => 2, 'retryAfterMs' => 1_000], $tooMuch);
    }

    public function testPeekReportsRemainingWithoutMutatingState(): void
    {
        $limiter = $this->limiter();
        $ctx = new FakeObjectContext();

        $limiter->hit($ctx, ['now' => 0]); // remaining is now 2, one write performed
        $writesBefore = $ctx->writeCount();
        $snapshotBefore = $ctx->snapshot();

        $peek = $limiter->peek($ctx);

        self::assertSame(['remaining' => 2, 'capacity' => self::CAPACITY], $peek);
        // The crux of "shared == read-only": peek performed no writes and left state byte-equal.
        self::assertSame($writesBefore, $ctx->writeCount());
        self::assertSame($snapshotBefore, $ctx->snapshot());
    }

    public function testPeekOnAFreshKeyReportsFullCapacity(): void
    {
        $limiter = $this->limiter();
        $ctx = new FakeObjectContext();

        $peek = $limiter->peek($ctx);

        self::assertSame(['remaining' => self::CAPACITY, 'capacity' => self::CAPACITY], $peek);
        self::assertSame(0, $ctx->writeCount());
    }

    public function testResetClearsStateSoTheNextHitStartsFresh(): void
    {
        $limiter = $this->limiter();
        $ctx = new FakeObjectContext();

        $limiter->hit($ctx, ['now' => 0]);
        $limiter->hit($ctx, ['now' => 0]);
        $limiter->hit($ctx, ['now' => 0]);
        self::assertFalse($limiter->hit($ctx, ['now' => 0])['allowed']);

        $limiter->reset($ctx);
        self::assertFalse($ctx->has('bucket'));

        // A fresh, full bucket: allowed again with capacity-1 remaining.
        $afterReset = $limiter->hit($ctx, ['now' => 0]);
        self::assertTrue($afterReset['allowed']);
        self::assertSame(self::CAPACITY - 1, $afterReset['remaining']);
    }

    public function testHitRejectsMissingNow(): void
    {
        $this->expectException(TerminalException::class);

        $this->limiter()->hit(new FakeObjectContext(), []);
    }

    public function testHitRejectsNegativeNow(): void
    {
        $this->expectException(TerminalException::class);

        $this->limiter()->hit(new FakeObjectContext(), ['now' => -1]);
    }

    public function testHitRejectsNonPositiveCost(): void
    {
        $this->expectException(TerminalException::class);

        $this->limiter()->hit(new FakeObjectContext(), ['now' => 0, 'cost' => 0]);
    }
}
