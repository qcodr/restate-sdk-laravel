<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Scheduling;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Drives the `Schedule::restate()` macro end to end against a faked HTTP layer: registers an
 * event the way an application's scheduler would, runs its callback, and asserts the exact
 * one-way ingress request it emits and the cron expression the frequency builder set.
 */
final class RestateSchedulerMacroTest extends SchedulerTestCase
{
    private const BASE_URL = 'http://localhost:8080';

    public function testMacroIsRegisteredOnSchedule(): void
    {
        self::assertTrue(Schedule::hasMacro('restate'));
    }

    public function testMacroReturnsASchedulableEvent(): void
    {
        $event = $this->schedule()->restate('OrderWorkflow', 'run', ['id' => 1]);

        self::assertInstanceOf(CallbackEvent::class, $event);
    }

    public function testMacroGivesTheEventAStableReadableDescription(): void
    {
        $event = $this->schedule()->restate('OrderWorkflow', 'run', ['id' => 1], 'order-1');

        self::assertSame('Restate dispatch OrderWorkflow/order-1/run', $event->getSummaryForDisplay());
    }

    public function testFrequencyBuilderSetsTheCronExpression(): void
    {
        $event = $this->schedule()->restate('OrderWorkflow', 'run', ['id' => 1])->everyMinute();

        self::assertSame('* * * * *', $event->getExpression());
    }

    public function testRunningTheDueCallbackSendsAOneWayInvocationToTheIngress(): void
    {
        Http::fake(['*' => Http::response(['invocationId' => 'inv_abc123'], 200)]);

        $event = $this->schedule()->restate('OrderWorkflow', 'run', ['id' => 1])->everyMinute();
        $event->run(app());

        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL . '/OrderWorkflow/run/send'
                && $request->body() === '{"id":1}';
        });
    }

    public function testTheDispatchCarriesAnIdempotencyKeyHeader(): void
    {
        Http::fake(['*' => Http::response(['invocationId' => 'inv_abc123'], 200)]);

        $event = $this->schedule()->restate('OrderWorkflow', 'run', ['id' => 1])->everyMinute();
        $event->run(app());

        // Presence is asserted here (so the scheduler opts every dispatch into ingress dedupe);
        // the exact `restate-schedule:…@<minute>` shape is pinned in RestateScheduleTest.
        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Idempotency-Key'));
    }

    public function testKeyedTargetSendsToTheKeyedIngressPath(): void
    {
        Http::fake(['*' => Http::response(['invocationId' => 'inv_keyed'], 200)]);

        $event = $this->schedule()->restate('OrderWorkflow', 'run', ['id' => 1], 'order-1')->daily();
        $event->run(app());

        Http::assertSent(static function (Request $request): bool {
            return $request->url() === self::BASE_URL . '/OrderWorkflow/order-1/run/send';
        });
    }

    private function schedule(): Schedule
    {
        return app(Schedule::class);
    }
}
