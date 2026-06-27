<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Laravel\Scheduling\RestateSchedule;

/**
 * Unit-tests the pure derivations behind the macro — the description and the minute-bucketed
 * idempotency key — without booting Laravel. These are the only parts of the integration that
 * carry a real decision (the dedupe window), so they are pinned down directly.
 */
final class RestateScheduleTest extends TestCase
{
    public function testDescribeMirrorsTheIngressPathForAnUnkeyedService(): void
    {
        self::assertSame(
            'Restate dispatch OrderWorkflow/run',
            RestateSchedule::describe('OrderWorkflow', 'run', null),
        );
    }

    public function testDescribeInsertsTheKeySegmentForAKeyedTarget(): void
    {
        self::assertSame(
            'Restate dispatch OrderWorkflow/order-1/run',
            RestateSchedule::describe('OrderWorkflow', 'run', 'order-1'),
        );
    }

    public function testIdempotencyKeyIsStableWithinTheSameMinuteSoADoubleTickDedupes(): void
    {
        // Two firings 29 seconds apart but inside the same minute — an overlapping `schedule:run`
        // — must collapse to one Restate invocation, i.e. produce an identical key.
        $first = new DateTimeImmutable('2026-06-27 03:00:30', new DateTimeZone('UTC'));
        $second = new DateTimeImmutable('2026-06-27 03:00:59', new DateTimeZone('UTC'));

        self::assertSame(
            RestateSchedule::idempotencyKey('OrderWorkflow', 'run', null, $first),
            RestateSchedule::idempotencyKey('OrderWorkflow', 'run', null, $second),
        );
    }

    public function testIdempotencyKeyRotatesOnTheNextMinuteSoTheNextTickStillRuns(): void
    {
        $thisMinute = new DateTimeImmutable('2026-06-27 03:00:30', new DateTimeZone('UTC'));
        $nextMinute = new DateTimeImmutable('2026-06-27 03:01:00', new DateTimeZone('UTC'));

        self::assertNotSame(
            RestateSchedule::idempotencyKey('OrderWorkflow', 'run', null, $thisMinute),
            RestateSchedule::idempotencyKey('OrderWorkflow', 'run', null, $nextMinute),
        );
    }

    public function testIdempotencyKeyDistinguishesDistinctKeyedTargetsInTheSameMinute(): void
    {
        // Same service/handler, different object keys must not dedupe against each other.
        $at = new DateTimeImmutable('2026-06-27 03:00:00', new DateTimeZone('UTC'));

        self::assertNotSame(
            RestateSchedule::idempotencyKey('OrderWorkflow', 'run', 'order-1', $at),
            RestateSchedule::idempotencyKey('OrderWorkflow', 'run', 'order-2', $at),
        );
    }

    public function testIdempotencyKeyIsNamespacedAndCarriesTheMinuteBucket(): void
    {
        $at = new DateTimeImmutable('2026-06-27 03:00:00', new DateTimeZone('UTC'));

        self::assertSame(
            'restate-schedule:OrderWorkflow/run@2026-06-27T03:00',
            RestateSchedule::idempotencyKey('OrderWorkflow', 'run', null, $at),
        );
    }
}
