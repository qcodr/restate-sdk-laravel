<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Telescope;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Laravel\Telescope\RestateDispatch;

/**
 * Unit-tests the pure ingress-URI parsing and tag-shaping of {@see RestateDispatch} — the core
 * the Telescope watcher relies on — without Laravel or Telescope. The parsing mirrors the wire
 * paths {@see \Qcodr\Restate\Laravel\Client\RestateClient} emits, so the cases double as a
 * contract check against the client.
 */
final class RestateDispatchTest extends TestCase
{
    private const BASE_URL = 'http://localhost:8080';

    public function testParsesUnkeyedServiceCall(): void
    {
        $dispatch = RestateDispatch::fromIngressUri(self::BASE_URL, self::BASE_URL . '/GreeterService/greet');

        self::assertNotNull($dispatch);
        self::assertSame('GreeterService', $dispatch->service);
        self::assertSame('greet', $dispatch->handler);
        self::assertNull($dispatch->key);
        self::assertSame(RestateDispatch::TYPE_CALL, $dispatch->type);
    }

    public function testParsesKeyedObjectCall(): void
    {
        $dispatch = RestateDispatch::fromIngressUri(self::BASE_URL, self::BASE_URL . '/RateLimiterObject/user-42/hit');

        self::assertNotNull($dispatch);
        self::assertSame('RateLimiterObject', $dispatch->service);
        self::assertSame('hit', $dispatch->handler);
        self::assertSame('user-42', $dispatch->key);
        self::assertSame(RestateDispatch::TYPE_CALL, $dispatch->type);
    }

    public function testParsesUnkeyedSend(): void
    {
        $dispatch = RestateDispatch::fromIngressUri(self::BASE_URL, self::BASE_URL . '/GreeterService/greet/send');

        self::assertNotNull($dispatch);
        self::assertSame('GreeterService', $dispatch->service);
        self::assertSame('greet', $dispatch->handler);
        self::assertNull($dispatch->key);
        self::assertSame(RestateDispatch::TYPE_SEND, $dispatch->type);
    }

    public function testParsesKeyedSendAndIgnoresDelayQuery(): void
    {
        $dispatch = RestateDispatch::fromIngressUri(
            self::BASE_URL,
            self::BASE_URL . '/Reminders/tenant-7/remind/send?delay=5000ms',
        );

        self::assertNotNull($dispatch);
        self::assertSame('Reminders', $dispatch->service);
        self::assertSame('remind', $dispatch->handler);
        self::assertSame('tenant-7', $dispatch->key);
        self::assertSame(RestateDispatch::TYPE_SEND, $dispatch->type);
    }

    public function testPercentDecodesSegments(): void
    {
        // The client rawurlencodes each segment, so a key like "a/b" arrives encoded.
        $dispatch = RestateDispatch::fromIngressUri(self::BASE_URL, self::BASE_URL . '/Svc/a%2Fb/handle');

        self::assertNotNull($dispatch);
        self::assertSame('a/b', $dispatch->key);
    }

    public function testReturnsNullForForeignHost(): void
    {
        $dispatch = RestateDispatch::fromIngressUri(self::BASE_URL, 'https://example.com/Service/handler');

        self::assertNull($dispatch);
    }

    public function testReturnsNullForNonInvocationPaths(): void
    {
        self::assertNull(RestateDispatch::fromIngressUri(self::BASE_URL, self::BASE_URL . '/discover'));
        self::assertNull(RestateDispatch::fromIngressUri(self::BASE_URL, self::BASE_URL . '/health'));
        self::assertNull(RestateDispatch::fromIngressUri(self::BASE_URL, self::BASE_URL . '/'));
    }

    public function testTagsForKeyedSendCarryEveryFacet(): void
    {
        $dispatch = RestateDispatch::fromIngressUri(self::BASE_URL, self::BASE_URL . '/Orders/order-9/cancel/send');

        self::assertNotNull($dispatch);
        self::assertSame(
            [
                'restate',
                'restate:type:send',
                'restate:service:Orders',
                'restate:handler:cancel',
                'restate:key:order-9',
            ],
            $dispatch->tags(),
        );
    }

    public function testTagsForUnkeyedCallOmitKeyFacet(): void
    {
        $dispatch = RestateDispatch::fromIngressUri(self::BASE_URL, self::BASE_URL . '/GreeterService/greet');

        self::assertNotNull($dispatch);
        self::assertSame(
            [
                'restate',
                'restate:type:call',
                'restate:service:GreeterService',
                'restate:handler:greet',
            ],
            $dispatch->tags(),
        );
    }
}
