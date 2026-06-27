<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Facades;

use PHPUnit\Framework\AssertionFailedError;
use Qcodr\Restate\Laravel\Client\RestateClient;
use Qcodr\Restate\Laravel\Facades\Restate;
use Qcodr\Restate\Laravel\Tests\TestCase;

/**
 * Drives the testing sugar exposed on the {@see Restate} facade — `fake()`, `assertCalled()`,
 * `assertSent()`, `assertCalledTimes()`, `assertNothingDispatched()` — proving each is a public
 * static entry point that delegates to {@see \Qcodr\Restate\Laravel\Testing\RestateFake}. Every
 * method is exercised through the facade (not RestateFake directly), with both a passing path
 * (the delegation produces a real assertion) and a failing path (a wrong expectation surfaces an
 * {@see AssertionFailedError}), so a removed delegation or a narrowed visibility is caught.
 */
final class RestateFacadeTest extends TestCase
{
    public function testFakeInterceptsDispatchesAndAssertCalledPasses(): void
    {
        Restate::fake();

        $this->client()->call('GreeterSvc', 'greet', 'x');

        Restate::assertCalled('GreeterSvc', 'greet');
    }

    public function testAssertCalledFailsForAnUnmatchedCall(): void
    {
        Restate::fake();

        $this->client()->call('GreeterSvc', 'greet', 'x');

        $this->expectException(AssertionFailedError::class);
        Restate::assertCalled('WrongService', 'greet');
    }

    public function testAssertSentPassesForAOneWaySend(): void
    {
        Restate::fake();

        $this->client()->send('OrderWorkflow', 'run', ['id' => 1], key: '1');

        Restate::assertSent('OrderWorkflow', 'run');
    }

    public function testAssertSentFailsWhenNothingWasSent(): void
    {
        Restate::fake();

        $this->expectException(AssertionFailedError::class);
        Restate::assertSent('OrderWorkflow', 'run');
    }

    public function testAssertCalledTimesPassesForTheExactCount(): void
    {
        Restate::fake();

        $this->client()->call('GreeterSvc', 'greet', 'a');
        $this->client()->call('GreeterSvc', 'greet', 'b');

        Restate::assertCalledTimes('GreeterSvc', 'greet', 2);
    }

    public function testAssertCalledTimesFailsForAWrongCount(): void
    {
        Restate::fake();

        $this->client()->call('GreeterSvc', 'greet', 'a');

        $this->expectException(AssertionFailedError::class);
        Restate::assertCalledTimes('GreeterSvc', 'greet', 2);
    }

    public function testAssertNothingDispatchedPassesWhenNothingWasSent(): void
    {
        Restate::fake();

        Restate::assertNothingDispatched();
    }

    public function testAssertNothingDispatchedFailsAfterADispatch(): void
    {
        Restate::fake();

        $this->client()->call('GreeterSvc', 'greet', 'x');

        $this->expectException(AssertionFailedError::class);
        Restate::assertNothingDispatched();
    }

    private function client(): RestateClient
    {
        return app(RestateClient::class);
    }
}
