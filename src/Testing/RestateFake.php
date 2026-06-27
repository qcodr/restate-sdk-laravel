<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Testing;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

/**
 * The `Bus::fake()` / `Http::fake()` equivalent for Restate dispatches.
 *
 * {@see \Qcodr\Restate\Laravel\Client\RestateClient} is `final` and routes every invocation
 * through Laravel's HTTP client {@see \Illuminate\Http\Client\Factory}, so it can be faked
 * *at the HTTP layer* without subclassing it or touching production code: this helper installs
 * an `Http::fake()` (so no real ingress is ever hit and a canned 200 JSON result is returned),
 * then translates Restate-level assertions — "was `OrderWorkflow::run` dispatched?" — into
 * `Http::assertSent(...)` truth tests over the exact ingress URL, method, and body the real
 * client emits.
 *
 * Because the assertions reconstruct the client's wire contract (percent-encoded
 * `/{Service}/{handler}` or `/{Service}/{key}/{handler}`, plus the `/send` suffix for one-way
 * dispatches), they match real calls without the caller having to spell out full URLs.
 *
 * Usage in a feature test:
 *
 * ```php
 * RestateFake::fake();
 *
 * // ...code under test calls Restate::client()->send('OrderWorkflow', 'run', ['id' => 1], key: '1');
 *
 * RestateFake::assertSent('OrderWorkflow', 'run', fn (mixed $body, ?string $key): bool
 *     => $body['id'] === 1 && $key === '1');
 * ```
 *
 * @see \Qcodr\Restate\Laravel\Client\RestateClient the dispatcher these fakes intercept
 */
final class RestateFake
{
    /**
     * Local-runtime ingress fallback, mirroring {@see \Qcodr\Restate\Laravel\RestateManager}
     * so the fake resolves the same base URL the real client would when config is absent.
     */
    private const DEFAULT_INGRESS_URL = 'http://localhost:8080';

    /**
     * Config key the ingress base URL is read from, identical to the one the service provider
     * builds the real client from — so the fake scopes its stub to the very URLs that client hits.
     */
    private const INGRESS_URL_CONFIG_KEY = 'restate.ingress.url';

    /**
     * Status the canned ingress stub answers with — a successful invocation acknowledgement.
     */
    private const OK_STATUS = 200;

    /**
     * Only POST is a Restate invocation; discovery/other verbs are never a dispatch.
     */
    private const POST_METHOD = 'POST';

    /**
     * Trailing path segment that marks a one-way (fire-and-forget) send, mirroring
     * {@see \Qcodr\Restate\Laravel\Client\RestateClient}'s `/send` suffix.
     */
    private const SEND_SEGMENT = 'send';

    /**
     * Response field {@see \Qcodr\Restate\Laravel\Client\RestateClient::send()} requires; the
     * default stub carries it so a faked `send()` returns a (fake) invocation id instead of
     * throwing on a missing envelope.
     */
    private const INVOCATION_ID_FIELD = 'invocationId';

    /**
     * The fake invocation id handed back to a faked `send()`.
     */
    private const DEFAULT_INVOCATION_ID = 'inv_fake';

    /**
     * Path-segment counts that denote a valid invocation target once any `/send` suffix is
     * stripped: `[Service, handler]` (unkeyed) and `[Service, key, handler]` (keyed).
     */
    private const SEGMENTS_UNKEYED = 2;
    private const SEGMENTS_KEYED = 3;

    /**
     * The ingress base URL the active fake is scoped to. Set by {@see self::fake()} so the
     * assertions ignore any unrelated outbound HTTP a feature test might make; each test calls
     * `fake()` first, so this never leaks a stale value into a real assertion.
     */
    private static ?string $ingressUrl = null;

    /**
     * Static-only helper: there is no instance state to construct.
     */
    private function __construct()
    {
    }

    /**
     * Install the HTTP-layer fake. After this, the real {@see \Qcodr\Restate\Laravel\Client\RestateClient}
     * hits no network — every ingress request resolves to a canned 200 JSON `$result` — and all
     * dispatches are recorded for the `assert*` methods below.
     *
     * @param string|null               $ingressUrl override the ingress base URL (defaults to
     *                                              `restate.ingress.url`, then the local runtime)
     * @param array<string, mixed>|null $result     the JSON body the stub returns; defaults to a
     *                                              `{"invocationId": …}` envelope so a faked
     *                                              `send()` succeeds. A custom body that omits
     *                                              `invocationId` will (correctly) make `send()`
     *                                              throw, exactly as a real ingress would.
     */
    public static function fake(?string $ingressUrl = null, ?array $result = null): void
    {
        $base = self::normaliseBaseUrl($ingressUrl ?? self::configuredIngressUrl());
        self::$ingressUrl = $base;

        $payload = $result ?? [self::INVOCATION_ID_FIELD => self::DEFAULT_INVOCATION_ID];

        // Scope the stub to the ingress; any other URL still gets an (empty) faked response, so
        // no real network call escapes the test regardless.
        Http::fake([
            $base . '/*' => Http::response($payload, self::OK_STATUS),
        ]);
    }

    /**
     * Assert a request/response invocation (`call()`) for `$service::$handler` was dispatched.
     *
     * The optional `$filter` receives the decoded JSON body (an array, scalar, or `null` for an
     * empty body) and the object/workflow key (or `null` for an unkeyed Service), and must
     * return `true` for the dispatch to count — letting a test pin down the exact payload.
     *
     * @param Closure(mixed, string|null): bool|null $filter
     */
    public static function assertCalled(string $service, string $handler, ?Closure $filter = null): void
    {
        Http::assertSent(static fn (Request $request): bool
            => self::matches($request, $service, $handler, false, $filter));
    }

    /**
     * Assert a one-way send (`send()`, the `/send` path) for `$service::$handler` was dispatched.
     *
     * `$filter` behaves exactly as in {@see self::assertCalled()}: it is handed the decoded JSON
     * body and the key.
     *
     * @param Closure(mixed, string|null): bool|null $filter
     */
    public static function assertSent(string $service, string $handler, ?Closure $filter = null): void
    {
        Http::assertSent(static fn (Request $request): bool
            => self::matches($request, $service, $handler, true, $filter));
    }

    /**
     * Assert that nothing was dispatched to Restate. Unlike a bare `Http::assertNothingSent()`,
     * this ignores unrelated outbound HTTP and only fails if an actual ingress invocation was
     * recorded — so a feature test that legitimately calls another API still passes.
     */
    public static function assertNothingDispatched(): void
    {
        Http::assertNotSent(static fn (Request $request): bool => self::isInvocation($request));
    }

    /**
     * Assert `$service::$handler` was dispatched (request/response) exactly `$times` times,
     * optionally narrowed by `$filter` (decoded body + key), as in {@see self::assertCalled()}.
     *
     * @param Closure(mixed, string|null): bool|null $filter
     */
    public static function assertCalledTimes(string $service, string $handler, int $times, ?Closure $filter = null): void
    {
        $count = Http::recorded(static fn (Request $request): bool
            => self::matches($request, $service, $handler, false, $filter))->count();

        Assert::assertSame($times, $count, \sprintf(
            'Expected Restate call %s::%s to be dispatched %d time(s), but it was dispatched %d time(s).',
            $service,
            $handler,
            $times,
            $count,
        ));
    }

    /**
     * The core truth test: does `$request` represent a dispatch to `$service::$handler`?
     *
     * `$oneWay` distinguishes a `send()` (the `/send` suffix must be present and is stripped
     * before target matching) from a `call()` (no suffix). When matched and a `$filter` is given,
     * the decoded body and key are handed to it for the final say.
     *
     * @param Closure(mixed, string|null): bool|null $filter
     */
    private static function matches(
        Request $request,
        string $service,
        string $handler,
        bool $oneWay,
        ?Closure $filter,
    ): bool {
        if (!self::isInvocation($request)) {
            return false;
        }

        $segments = self::pathSegments($request->url());

        if ($oneWay) {
            // A one-way send is the same target path plus a trailing `/send`; require and remove
            // it so the remaining segments are the bare `[Service, (key,) handler]` target.
            $last = \array_pop($segments);
            if ($last !== self::SEND_SEGMENT) {
                return false;
            }
        }

        $target = self::splitTarget($segments);
        if ($target === null) {
            return false;
        }

        if ($target['service'] !== $service || $target['handler'] !== $handler) {
            return false;
        }

        if ($filter === null) {
            return true;
        }

        return $filter(self::decodeBody($request->body()), $target['key']) === true;
    }

    /**
     * Is `$request` a Restate invocation at all — a POST to the faked ingress with at least a
     * service and a handler segment? Used both by the matchers and by
     * {@see self::assertNothingDispatched()}.
     */
    private static function isInvocation(Request $request): bool
    {
        if ($request->method() !== self::POST_METHOD) {
            return false;
        }

        if (!\str_starts_with($request->url(), self::baseUrl())) {
            return false;
        }

        return \count(self::pathSegments($request->url())) >= self::SEGMENTS_UNKEYED;
    }

    /**
     * Resolve a `[Service, (key,) handler]` segment list into its parts, or `null` when the
     * shape is neither the unkeyed nor the keyed form (so it is not a target we recognise).
     *
     * @param list<string> $segments
     *
     * @return array{service: string, handler: string, key: string|null}|null
     */
    private static function splitTarget(array $segments): ?array
    {
        return match (\count($segments)) {
            self::SEGMENTS_UNKEYED => ['service' => $segments[0], 'handler' => $segments[1], 'key' => null],
            self::SEGMENTS_KEYED => ['service' => $segments[0], 'handler' => $segments[2], 'key' => $segments[1]],
            default => null,
        };
    }

    /**
     * Split a request URL into its percent-decoded, non-empty path segments — the inverse of the
     * real client's `rawurlencode`-per-segment path building, so a key like `acme/tenant` or an
     * email round-trips back to the value the caller passed.
     *
     * @return list<string>
     */
    private static function pathSegments(string $url): array
    {
        $path = \parse_url($url, PHP_URL_PATH);
        if (!\is_string($path)) {
            return [];
        }

        $segments = \array_values(\array_filter(
            \explode('/', $path),
            static fn (string $segment): bool => $segment !== '',
        ));

        return \array_map(static fn (string $segment): string => \rawurldecode($segment), $segments);
    }

    /**
     * Decode the JSON request body the way a handler would receive its argument: an empty body
     * (the real client's encoding of a `null` payload) becomes `null`, otherwise the top-level
     * JSON value — array, or a bare scalar a Service handler may take. Throws on malformed JSON
     * rather than silently yielding `null`, since a body the fake itself recorded must be valid.
     */
    private static function decodeBody(string $body): mixed
    {
        if ($body === '') {
            return null;
        }

        return \json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The ingress base URL the assertions scope to: the value the active {@see self::fake()} was
     * installed with, or — defensively, if an assertion runs without one — the configured URL.
     */
    private static function baseUrl(): string
    {
        return self::$ingressUrl ?? self::normaliseBaseUrl(self::configuredIngressUrl());
    }

    /**
     * Read the ingress base URL from config, falling back to the local runtime when unset or not
     * a usable string — matching how the real client is built.
     */
    private static function configuredIngressUrl(): string
    {
        $url = config(self::INGRESS_URL_CONFIG_KEY);

        return \is_string($url) && $url !== '' ? $url : self::DEFAULT_INGRESS_URL;
    }

    /**
     * Drop any trailing slash so base-URL prefix checks and the `{base}/*` stub pattern join the
     * client's leading-slash paths cleanly (`http://host` + `/Svc/h`, never `http://host//Svc/h`).
     */
    private static function normaliseBaseUrl(string $url): string
    {
        return \rtrim($url, '/');
    }
}
