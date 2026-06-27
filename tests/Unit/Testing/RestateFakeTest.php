<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Testing;

use PHPUnit\Framework\AssertionFailedError;
use Qcodr\Restate\Laravel\Client\RestateClient;
use Qcodr\Restate\Laravel\Testing\RestateFake;
use Qcodr\Restate\Laravel\Tests\TestCase;

/**
 * Proves {@see RestateFake} intercepts the real {@see RestateClient} (no ingress is stood up)
 * and that its Restate-level assertions match the exact wire shape the client emits — including
 * the keyed path, the one-way `/send` suffix, and the decoded body/key handed to a `$filter`.
 */
final class RestateFakeTest extends TestCase
{
    public function testAssertCalledMatchesAServiceCall(): void
    {
        RestateFake::fake();

        $this->client()->call('GreeterSvc', 'greet', 'x');

        RestateFake::assertCalled('GreeterSvc', 'greet');
    }

    public function testAssertCalledMatchesAKeyedCallAndExposesTheKey(): void
    {
        RestateFake::fake();

        $this->client()->call('RateLimiterObject', 'hit', ['cost' => 1], 'user-42');

        RestateFake::assertCalled(
            'RateLimiterObject',
            'hit',
            static fn (mixed $body, ?string $key): bool => $body === ['cost' => 1] && $key === 'user-42',
        );
    }

    public function testAssertSentMatchesAOneWaySendAndExposesBodyAndKey(): void
    {
        RestateFake::fake();

        $this->client()->send('OrderWorkflow', 'run', ['id' => 1], key: '1');

        RestateFake::assertSent(
            'OrderWorkflow',
            'run',
            static fn (mixed $body, ?string $key): bool => \is_array($body) && $body['id'] === 1 && $key === '1',
        );
    }

    public function testFakeReturnsAnInvocationIdSoSendSucceeds(): void
    {
        RestateFake::fake();

        $invocationId = $this->client()->send('OrderWorkflow', 'run', ['id' => 1], key: '1');

        self::assertSame('inv_fake', $invocationId);
    }

    public function testFakeReturnsTheProgrammableDefaultResultToCall(): void
    {
        RestateFake::fake(result: ['greeting' => 'Hello world', 'invocationId' => 'inv_x']);

        $result = $this->client()->call('GreeterSvc', 'greet', 'world');

        self::assertSame(['greeting' => 'Hello world', 'invocationId' => 'inv_x'], $result);
    }

    public function testAssertCalledFailsForAWrongService(): void
    {
        RestateFake::fake();

        $this->client()->call('GreeterSvc', 'greet', 'x');

        $this->expectException(AssertionFailedError::class);
        RestateFake::assertCalled('WrongService', 'greet');
    }

    public function testAssertCalledFailsForAWrongHandler(): void
    {
        RestateFake::fake();

        $this->client()->call('GreeterSvc', 'greet', 'x');

        $this->expectException(AssertionFailedError::class);
        RestateFake::assertCalled('GreeterSvc', 'farewell');
    }

    public function testAssertCalledDoesNotMatchAOneWaySend(): void
    {
        // A `send()` must not satisfy `assertCalled()` (request/response): the `/send` path
        // shifts the handler segment, so the handler never matches.
        RestateFake::fake();

        $this->client()->send('GreeterSvc', 'greet', 'x');

        $this->expectException(AssertionFailedError::class);
        RestateFake::assertCalled('GreeterSvc', 'greet');
    }

    public function testAssertSentFailsWhenTheFilterRejectsTheBody(): void
    {
        RestateFake::fake();

        $this->client()->send('OrderWorkflow', 'run', ['id' => 1], key: '1');

        $this->expectException(AssertionFailedError::class);
        RestateFake::assertSent(
            'OrderWorkflow',
            'run',
            static fn (mixed $body): bool => \is_array($body) && $body['id'] === 999,
        );
    }

    public function testAssertNothingDispatchedPassesWhenNothingWasSent(): void
    {
        RestateFake::fake();

        RestateFake::assertNothingDispatched();
    }

    public function testAssertNothingDispatchedFailsAfterADispatch(): void
    {
        RestateFake::fake();

        $this->client()->call('GreeterSvc', 'greet', 'x');

        $this->expectException(AssertionFailedError::class);
        RestateFake::assertNothingDispatched();
    }

    public function testAssertCalledTimesCountsMatchingCalls(): void
    {
        RestateFake::fake();

        $this->client()->call('GreeterSvc', 'greet', 'a');
        $this->client()->call('GreeterSvc', 'greet', 'b');
        $this->client()->call('OtherSvc', 'greet', 'c');

        RestateFake::assertCalledTimes('GreeterSvc', 'greet', 2);
    }

    public function testAFilterReceivesNullBodyForANoArgumentCall(): void
    {
        // A null payload is sent as an empty body; the filter should see it decoded back to null.
        RestateFake::fake();

        $this->client()->call('GreeterSvc', 'ping');

        RestateFake::assertCalled(
            'GreeterSvc',
            'ping',
            static fn (mixed $body, ?string $key): bool => $body === null && $key === null,
        );
    }

    /**
     * The real, container-resolved dispatcher — the thing the fake must intercept.
     */
    private function client(): RestateClient
    {
        return app(RestateClient::class);
    }
}
