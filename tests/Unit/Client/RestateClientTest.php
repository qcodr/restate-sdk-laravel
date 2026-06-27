<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Client;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Qcodr\Restate\Laravel\Client\RestateClient;
use Qcodr\Restate\Laravel\Client\RestateRequestException;
use Qcodr\Restate\Laravel\Facades\Restate;
use Qcodr\Restate\Laravel\RestateManager;
use Qcodr\Restate\Laravel\Tests\TestCase;

/**
 * Drives {@see RestateClient} against a faked HTTP layer (`Http::fake()`), asserting the
 * exact ingress URLs, headers, and bodies it emits and the values it returns — the wire
 * contract a real Restate ingress would see, without standing one up.
 */
final class RestateClientTest extends TestCase
{
    private const BASE_URL = 'http://localhost:8080';

    public function testCallPostsToServicePathAndReturnsDecodedResult(): void
    {
        Http::fake([
            '*' => Http::response(['greeting' => 'Hello world'], 200),
        ]);

        $result = $this->client()->call('GreeterService', 'greet', ['name' => 'world']);

        self::assertSame(['greeting' => 'Hello world'], $result);

        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL . '/GreeterService/greet'
                && $request->body() === '{"name":"world"}'
                && $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function testCallInsertsKeySegmentForKeyedObjectOrWorkflow(): void
    {
        Http::fake(['*' => Http::response(['allowed' => true], 200)]);

        $this->client()->call('RateLimiterObject', 'hit', ['cost' => 1], 'user-42');

        Http::assertSent(static function (Request $request): bool {
            return $request->url() === self::BASE_URL . '/RateLimiterObject/user-42/hit';
        });
    }

    public function testCallEncodesAScalarPayloadAsTopLevelJson(): void
    {
        // A Service handler may take a bare scalar (e.g. `greet(string $name)`), so the body
        // must be top-level JSON — something `->post($url, $array)` could not express.
        Http::fake(['*' => Http::response('"Hello world"', 200, ['Content-Type' => 'application/json'])]);

        $result = $this->client()->call('GreeterService', 'greet', 'world');

        self::assertSame('Hello world', $result);
        Http::assertSent(static fn (Request $request): bool => $request->body() === '"world"');
    }

    public function testCallWithNullPayloadSendsAnEmptyBody(): void
    {
        Http::fake(['*' => Http::response('null', 200, ['Content-Type' => 'application/json'])]);

        $this->client()->call('GreeterService', 'ping');

        Http::assertSent(static fn (Request $request): bool => $request->body() === '');
    }

    public function testSendHitsTheSendPathAndReturnsInvocationId(): void
    {
        Http::fake([
            '*' => Http::response(['invocationId' => 'inv_abc123', 'status' => 'Accepted'], 200),
        ]);

        $invocationId = $this->client()->send('GreeterService', 'greet', ['name' => 'world']);

        self::assertSame('inv_abc123', $invocationId);

        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL . '/GreeterService/greet/send';
        });
    }

    public function testSendAppendsTheDelayQueryParameterInMilliseconds(): void
    {
        Http::fake(['*' => Http::response(['invocationId' => 'inv_delayed'], 200)]);

        $this->client()->send('ReminderService', 'remind', ['id' => 7], null, null, 5_000);

        Http::assertSent(static function (Request $request): bool {
            return $request->url() === self::BASE_URL . '/ReminderService/remind/send?delay=5000ms';
        });
    }

    public function testSendThrowsWhenTheResponseHasNoInvocationId(): void
    {
        Http::fake(['*' => Http::response(['status' => 'Accepted'], 200)]);

        try {
            $this->client()->send('GreeterService', 'greet');
            self::fail('Expected RestateRequestException for a send response without invocationId.');
        } catch (RestateRequestException $e) {
            self::assertSame(200, $e->status);
        }
    }

    public function testSendThrowsAFailedRequestExceptionOnNon2xxResponse(): void
    {
        // The body carries a valid invocationId so only the non-2xx guard can produce an error:
        // a missing throw would (wrongly) return the id instead of failing.
        Http::fake(['*' => Http::response(['invocationId' => 'inv_should_not_return'], 500)]);

        try {
            $this->client()->send('GreeterService', 'greet', ['name' => 'world']);
            self::fail('Expected RestateRequestException on a non-2xx ingress send response.');
        } catch (RestateRequestException $e) {
            self::assertSame(500, $e->status);
            self::assertStringContainsString('failed with HTTP 500', $e->getMessage());
        }
    }

    public function testSetsIdempotencyKeyHeaderWhenProvided(): void
    {
        Http::fake(['*' => Http::response(['greeting' => 'hi'], 200)]);

        $this->client()->call('GreeterService', 'greet', ['name' => 'world'], null, 'idem-key-1');

        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'idem-key-1'));
    }

    public function testForwardsCustomHeadersOnCallAndSend(): void
    {
        // Auth/tenant propagation rides on the optional $headers argument of call()/send().
        Http::fake(['*' => Http::response(['invocationId' => 'inv_h', 'greeting' => 'hi'], 200)]);

        $this->client()->call('GreeterService', 'greet', ['name' => 'world'], null, null, ['x-restate-user' => '42']);
        $this->client()->send('OrderWorkflow', 'run', ['id' => 1], 'o-1', null, null, ['x-restate-tenant' => 'acme']);

        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('x-restate-user', '42'));
        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('x-restate-tenant', 'acme'));
    }

    public function testSetsAuthorizationHeaderWhenTokenIsConfigured(): void
    {
        // Configure the ingress token, then drop the singletons the provider memoised at boot
        // so the client is rebuilt from this config — exercising the full config → client wiring.
        app(Repository::class)->set('restate.ingress.token', 'secret-token');
        app()->forgetInstance(RestateClient::class);
        app()->forgetInstance(RestateManager::class);

        Http::fake(['*' => Http::response(['greeting' => 'hi'], 200)]);

        $this->client()->call('GreeterService', 'greet', ['name' => 'world']);

        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret-token'));
    }

    public function testOmitsAuthorizationHeaderWhenNoTokenIsConfigured(): void
    {
        Http::fake(['*' => Http::response(['greeting' => 'hi'], 200)]);

        $this->client()->call('GreeterService', 'greet', ['name' => 'world']);

        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization') === false);
    }

    public function testThrowsPackageExceptionCarryingStatusAndBodyOnNon2xx(): void
    {
        Http::fake(['*' => Http::response('rate limit exceeded', 429)]);

        try {
            $this->client()->call('RateLimiterObject', 'hit', ['cost' => 1], 'user-42');
            self::fail('Expected RestateRequestException on a non-2xx ingress response.');
        } catch (RestateRequestException $e) {
            self::assertSame(429, $e->status);
            self::assertSame('rate limit exceeded', $e->responseBody);
            self::assertStringContainsString('429', $e->getMessage());
        }
    }

    public function testResolvesAsASharedSingletonFromTheContainer(): void
    {
        self::assertSame(app(RestateClient::class), app(RestateClient::class));
    }

    public function testFacadeExposesTheSameClientInstance(): void
    {
        self::assertSame(app(RestateClient::class), Restate::client());
    }

    public function testForwardsDefaultHeadersAndPerCallOverridesThem(): void
    {
        Http::fake(['*' => Http::response(['greeting' => 'hi'], 200)]);

        $client = new RestateClient(
            app(Factory::class),
            self::BASE_URL,
            null,
            static fn (): array => ['x-restate-user' => '7', 'x-restate-tenant' => 'acme'],
        );

        $client->call('GreeterService', 'greet', ['name' => 'world'], null, null, ['x-restate-tenant' => 'globex']);

        Http::assertSent(static function (Request $request): bool {
            return $request->hasHeader('x-restate-user', '7')          // default forwarded
                && $request->hasHeader('x-restate-tenant', 'globex');  // per-call overrides the default
        });
    }

    public function testForwardOutboundConfigAutoAttachesTheTenantHeader(): void
    {
        // Enable auto-forward, drop the memoised singleton so it rebuilds with the closure,
        // and put a tenant on Laravel's Context — ForwardsAuthHeaders turns it into a header.
        app(Repository::class)->set('restate.auth.forward_outbound', true);
        app()->forgetInstance(RestateClient::class);
        Context::add('restate.tenant', 'acme');

        Http::fake(['*' => Http::response(['greeting' => 'hi'], 200)]);
        $this->client()->call('GreeterService', 'greet', ['name' => 'world']);

        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('x-restate-tenant', 'acme'));
    }

    private function client(): RestateClient
    {
        return app(RestateClient::class);
    }
}
