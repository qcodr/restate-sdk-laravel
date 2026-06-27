<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Client;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Client side of the integration: starts Restate invocations from ordinary Laravel code
 * (controllers, jobs, event listeners) by talking to the Restate **ingress** over HTTP.
 *
 * This is the dispatcher, not the durable runtime — it has no journal and no replay. The
 * durability lives in the Restate service the ingress routes to; this class only kicks that
 * service off and (for {@see self::call()}) blocks on its result.
 *
 * Ingress shape implemented (see docs/usecases/dispatch.md for the rationale and the one
 * assumption flagged there):
 *
 *  - call + await ........ `POST {base}/{Service}/{handler}`
 *                          `POST {base}/{Object|Workflow}/{key}/{handler}`     → handler JSON result
 *  - one-way send ........ the same path with a `/send` suffix                 → `{"invocationId": "…"}`
 *  - delayed send ........ `?delay=<n>ms` (humantime) on the send path
 *  - idempotency ......... `Idempotency-Key: <key>` header (dedupes retries at the ingress)
 *  - auth ................ optional `Authorization: Bearer <token>` from config
 *
 * The Laravel HTTP {@see Factory} is injected (rather than the `Http` facade used statically)
 * so tests can drive it with `Http::fake()` and assert on the outgoing requests, and so the
 * base URL and bearer token are bound once from config at construction.
 */
final class RestateClient
{
    /**
     * Header the Restate ingress reads to deduplicate retried invocations. Two requests with
     * the same key (and target) execute the handler once; the second observes the first's
     * outcome. Case-insensitive on the wire (RFC 7230 §3.2), spelled canonically here.
     */
    private const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    /**
     * Content type for the JSON-encoded handler argument we put on the wire.
     */
    private const CONTENT_TYPE = 'application/json';

    /**
     * Suffix that turns a request/response invocation path into a one-way (fire-and-forget)
     * send at the ingress.
     */
    private const SEND_SUFFIX = '/send';

    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly ?string $token = null,
    ) {
    }

    /**
     * Invoke a handler and block until it returns, yielding the decoded JSON result.
     *
     * Pass `$key` to address a Virtual Object or Workflow instance (the segment between
     * service and handler); omit it for a plain Service. `$payload` is JSON-encoded as the
     * single handler argument — `null` (the default) sends an empty body, i.e. "no argument",
     * which is how a no-argument handler is called.
     *
     * @param string      $service        the `#[Service]` / `#[VirtualObject]` / `#[Workflow]` name
     * @param string      $handler        the handler method name
     * @param mixed       $payload        the handler argument; JSON-encoded (null ⇒ empty body)
     * @param string|null $key            object/workflow key, or null for an unkeyed Service
     * @param string|null $idempotencyKey opt-in dedupe key sent as `Idempotency-Key`
     *
     * @return mixed the handler's decoded JSON result
     *
     * @throws RestateRequestException                      on a non-2xx ingress response
     * @throws \Illuminate\Http\Client\ConnectionException  if the ingress is unreachable
     */
    /**
     * @param array<string, string>|null $headers extra request headers forwarded to the
     *                                             ingress (e.g. auth/tenant propagation)
     */
    public function call(
        string $service,
        string $handler,
        mixed $payload = null,
        ?string $key = null,
        ?string $idempotencyKey = null,
        ?array $headers = null,
    ): mixed {
        $path = $this->invocationPath($service, $handler, $key);
        $response = $this->dispatch($path, $payload, $idempotencyKey, null, $headers);

        if (!$response->successful()) {
            throw RestateRequestException::forFailedRequest($path, $response->status(), $response->body());
        }

        return $response->json();
    }

    /**
     * Fire-and-forget: enqueue an invocation at the ingress and return immediately with its
     * invocation id, without waiting for the handler to run.
     *
     * `$delayMs` schedules the invocation that many milliseconds into the future (a durable,
     * restart-surviving timer on the Restate side) — the natural primitive for reminders,
     * debounced work, or retry-after scheduling from a controller or job.
     *
     * @param string      $service        the `#[Service]` / `#[VirtualObject]` / `#[Workflow]` name
     * @param string      $handler        the handler method name
     * @param mixed       $payload        the handler argument; JSON-encoded (null ⇒ empty body)
     * @param string|null $key            object/workflow key, or null for an unkeyed Service
     * @param string|null $idempotencyKey opt-in dedupe key sent as `Idempotency-Key`
     * @param int|null    $delayMs        delay before execution, in milliseconds (null ⇒ now)
     * @param array<string, string>|null $headers extra request headers forwarded to the
     *                                             ingress (e.g. auth/tenant propagation)
     *
     * @return string the invocation id the ingress assigns (e.g. `inv_…`)
     *
     * @throws RestateRequestException                      on a non-2xx response, or a 2xx
     *                                                      response without an `invocationId`
     * @throws \Illuminate\Http\Client\ConnectionException  if the ingress is unreachable
     */
    public function send(
        string $service,
        string $handler,
        mixed $payload = null,
        ?string $key = null,
        ?string $idempotencyKey = null,
        ?int $delayMs = null,
        ?array $headers = null,
    ): string {
        $path = $this->invocationPath($service, $handler, $key) . self::SEND_SUFFIX;
        $response = $this->dispatch($path, $payload, $idempotencyKey, $delayMs, $headers);

        if (!$response->successful()) {
            throw RestateRequestException::forFailedRequest($path, $response->status(), $response->body());
        }

        $invocationId = $response->json('invocationId');
        if (!\is_string($invocationId) || $invocationId === '') {
            throw RestateRequestException::forUnexpectedSendResponse($path, $response->status(), $response->body());
        }

        return $invocationId;
    }

    /**
     * Build, authenticate, and POST one invocation request to the ingress.
     *
     * Everything common to {@see self::call()} and {@see self::send()} converges here: the
     * base URL, optional bearer auth, optional idempotency and delay, and the JSON body. The
     * request is non-async, so {@see PendingRequest::post()} resolves to a {@see Response}.
     */
    /**
     * @param array<string, string>|null $headers
     */
    private function dispatch(string $path, mixed $payload, ?string $idempotencyKey, ?int $delayMs, ?array $headers = null): Response
    {
        $request = $this->http->baseUrl($this->baseUrl);

        if ($this->token !== null) {
            $request = $request->withToken($this->token);
        }

        if ($headers !== null && $headers !== []) {
            $request = $request->withHeaders($headers);
        }

        if ($idempotencyKey !== null) {
            $request = $request->withHeaders([self::IDEMPOTENCY_HEADER => $idempotencyKey]);
        }

        if ($delayMs !== null) {
            // Restate's `delay` parameter accepts a humantime duration; the millisecond form
            // (`<n>ms`) is the exact, lossless mapping of the integer argument exposed here.
            $request = $request->withQueryParameters(['delay' => $delayMs . 'ms']);
        }

        return $request
            ->withBody($this->encodeBody($payload), self::CONTENT_TYPE)
            ->post($path);
    }

    /**
     * Compose the ingress invocation path. A key is inserted only when given, yielding
     * `/{Service}/{handler}` for a Service and `/{Service}/{key}/{handler}` for a keyed
     * Virtual Object or Workflow. Every segment is percent-encoded so a user-supplied key
     * (a tenant id, an email, …) can never inject extra path segments or traversal.
     */
    private function invocationPath(string $service, string $handler, ?string $key): string
    {
        $segments = $key === null
            ? [$service, $handler]
            : [$service, $key, $handler];

        return '/' . \implode('/', \array_map(
            static fn (string $segment): string => \rawurlencode($segment),
            $segments,
        ));
    }

    /**
     * JSON-encode the handler argument. `null` becomes an empty body — Restate treats that as
     * "no argument", the right thing for a no-arg handler — while any other value (including a
     * scalar like `"world"` or `42`, which a Service handler may legitimately accept) is
     * encoded as top-level JSON. Throws on un-encodable input rather than silently sending
     * `false`.
     */
    private function encodeBody(mixed $payload): string
    {
        if ($payload === null) {
            return '';
        }

        return \json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
