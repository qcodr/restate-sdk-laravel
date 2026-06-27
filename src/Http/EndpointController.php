<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;

/**
 * Serves the Restate {@see \Qcodr\Restate\Sdk\Endpoint\Endpoint} from inside the Laravel
 * app over request/response — discovery and one invocation slice per request. It is the
 * Laravel counterpart of the SDK's {@see \Qcodr\Restate\Sdk\Server\Psr15Handler}: it
 * adapts the incoming {@see Request} onto the framework-agnostic {@see RequestProcessor}
 * and renders the result back into a Laravel {@see Response}. All protocol behaviour lives
 * in the processor.
 *
 * The catch-all route mounts this at the configured prefix; the processor routes by path
 * suffix (`/discover`, `/health`) and the `/invoke/` marker, so the mount prefix needs no
 * stripping. This host advertises REQUEST_RESPONSE — for bidirectional streaming
 * (cancellation, signals, fewer re-invokes) run `php artisan restate:serve` instead.
 */
final class EndpointController
{
    public function __construct(private readonly RequestProcessor $processor)
    {
    }

    public function handle(Request $request): Response
    {
        $httpRequest = new HttpRequest(
            $request->getMethod(),
            '/' . \ltrim($request->path(), '/'),
            self::flattenHeaders($request),
            $request->getContent(),
        );

        $httpResponse = $this->processor->process($httpRequest);

        return new Response($httpResponse->body, $httpResponse->status, $httpResponse->headers);
    }

    /**
     * Collapses Laravel's multi-valued header bag into the lower-cased
     * `array<string, string>` shape {@see HttpRequest} expects, joining repeated values
     * with ", " per RFC 7230 §3.2.2 (mirrors {@see \Qcodr\Restate\Sdk\Server\Psr15Handler}).
     *
     * @return array<string, string>
     */
    private static function flattenHeaders(Request $request): array
    {
        $flattened = [];
        foreach ($request->headers->all() as $name => $values) {
            $flattened[\strtolower((string) $name)] = \implode(
                ', ',
                \array_map(static fn (mixed $value): string => (string) $value, $values),
            );
        }

        return $flattened;
    }
}
