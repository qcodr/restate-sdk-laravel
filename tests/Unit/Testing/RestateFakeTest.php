<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Testing;

use Illuminate\Support\Facades\Http;
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

    public function testAssertCalledTimesIgnoresNonInvocationRequests(): void
    {
        // A non-POST request recorded alongside a real dispatch must not be counted as a Restate
        // invocation (a GET to the ingress is faked, so no network escapes).
        RestateFake::fake();

        Http::get('http://localhost:8080/health');
        $this->client()->call('GreeterSvc', 'greet', 'x');

        RestateFake::assertCalledTimes('GreeterSvc', 'greet', 1);
    }

    public function testAssertSentRejectsARequestResponseCall(): void
    {
        // A `call()` has no `/send` suffix, so it must never satisfy `assertSent()`.
        RestateFake::fake();

        $this->client()->call('GreeterSvc', 'greet', 'x');

        $this->expectException(AssertionFailedError::class);
        RestateFake::assertSent('GreeterSvc', 'greet');
    }

    public function testAssertCalledRejectsAPathThatIsNeitherKeyedNorUnkeyed(): void
    {
        // A four-segment POST to the ingress is not a recognisable target shape, so no assertion
        // can match it.
        RestateFake::fake();

        Http::post('http://localhost:8080/a/b/c/d', ['x' => 1]);

        $this->expectException(AssertionFailedError::class);
        RestateFake::assertCalled('a', 'b');
    }

    public function testAssertNothingDispatchedIgnoresNonPostRequestsToTheIngress(): void
    {
        // A GET to an invocation-shaped ingress path is not a dispatch (only POST is).
        RestateFake::fake();

        Http::get('http://localhost:8080/GreeterSvc/greet');

        RestateFake::assertNothingDispatched();
    }

    public function testAssertNothingDispatchedIgnoresPostsToAForeignHost(): void
    {
        // A POST to another host, even with an invocation-shaped path, is not a Restate dispatch.
        // Stub the foreign host too so the call is faked rather than hitting the network.
        RestateFake::fake();
        Http::fake(['https://api.example.com/*' => Http::response('', 200)]);

        Http::post('https://api.example.com/GreeterSvc/greet', ['x' => 1]);

        RestateFake::assertNothingDispatched();
    }

    public function testAssertCalledPercentDecodesAKeySegment(): void
    {
        // The client rawurlencodes a key containing "/" to %2F; the fake must decode it back so a
        // filter sees the original key value.
        RestateFake::fake();

        $this->client()->call('Svc', 'handle', ['x' => 1], 'a/b');

        RestateFake::assertCalled(
            'Svc',
            'handle',
            static fn (mixed $body, ?string $key): bool => $key === 'a/b',
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
