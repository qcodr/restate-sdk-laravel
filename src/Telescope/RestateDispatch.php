<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Telescope;

/**
 * An immutable description of one Restate ingress dispatch, parsed from the outgoing HTTP
 * request the {@see \Qcodr\Restate\Laravel\Client\RestateClient} sends to the Restate ingress.
 *
 * This is the value object the {@see RestateWatcher} turns into Telescope tags. It mirrors the
 * ingress path shape the client emits — `/{Service}/{handler}` for a plain service,
 * `/{Service}/{key}/{handler}` for a keyed Virtual Object or Workflow, with an optional
 * trailing `/send` marking a fire-and-forget dispatch — so the same request Telescope already
 * records as a `client_request` can be filtered by the Restate service, handler, key and call
 * style behind it.
 */
final class RestateDispatch
{
    /**
     * Blocking call-and-await dispatch (the ingress path without a `/send` suffix).
     */
    public const TYPE_CALL = 'call';

    /**
     * Fire-and-forget dispatch (the ingress path with a `/send` suffix).
     */
    public const TYPE_SEND = 'send';

    /**
     * Trailing path segment the ingress reads as "one-way send".
     */
    private const SEND_SEGMENT = 'send';

    /**
     * Prefix every tag shares, so a Telescope operator can filter the whole family with one
     * `restate` tag and drill down with the `restate:*` facets.
     */
    private const TAG_PREFIX = 'restate';

    /**
     * @param non-empty-string                       $service the Restate service / object / workflow name
     * @param non-empty-string                       $handler the handler method name
     * @param non-empty-string|null                  $key     the object/workflow key, or null for a plain service
     * @param self::TYPE_CALL|self::TYPE_SEND        $type    blocking call vs one-way send
     */
    private function __construct(
        public readonly string $service,
        public readonly string $handler,
        public readonly ?string $key,
        public readonly string $type,
    ) {
    }

    /**
     * Parse a Restate ingress dispatch from an outgoing request URI, or return null when the
     * URI is not a Restate invocation (a different host, or a non-invocation path such as the
     * `/discover` or `/health` endpoints).
     *
     * `$baseUrl` is the configured ingress base; only URIs under it are considered. The query
     * string (e.g. a `?delay=…ms` on a send) and fragment are ignored, and each path segment is
     * percent-decoded to recover the original service/handler/key the client encoded.
     *
     * @param string $baseUrl the Restate ingress base URL the client posts to
     * @param string $uri      the full outgoing request URI Telescope recorded
     */
    public static function fromIngressUri(string $baseUrl, string $uri): ?self
    {
        $base = \rtrim($baseUrl, '/');

        $path = \explode('#', \explode('?', $uri, 2)[0], 2)[0];
        if ($base !== '' && !\str_starts_with($path, $base)) {
            return null;
        }

        $relative = $base === '' ? $path : \substr($path, \strlen($base));

        $segments = \array_values(\array_filter(
            \array_map(
                static fn (string $segment): string => \rawurldecode($segment),
                \explode('/', \trim($relative, '/')),
            ),
            static fn (string $segment): bool => $segment !== '',
        ));

        $type = self::TYPE_CALL;
        if (($segments[\count($segments) - 1] ?? null) === self::SEND_SEGMENT) {
            $type = self::TYPE_SEND;
            $segments = \array_slice($segments, 0, -1);
        }

        if (\count($segments) === 2) {
            return new self($segments[0], $segments[1], null, $type);
        }

        if (\count($segments) === 3) {
            return new self($segments[0], $segments[2], $segments[1], $type);
        }

        return null;
    }

    /**
     * The Telescope tags identifying this dispatch: the shared `restate` tag plus `restate:*`
     * facets for the call style, service, handler and (when keyed) the instance key — so an
     * operator can filter Telescope's HTTP-client entries down to a single Restate service or
     * even a single object instance.
     *
     * @return non-empty-list<non-empty-string>
     */
    public function tags(): array
    {
        $tags = [
            self::TAG_PREFIX,
            self::TAG_PREFIX . ':type:' . $this->type,
            self::TAG_PREFIX . ':service:' . $this->service,
            self::TAG_PREFIX . ':handler:' . $this->handler,
        ];

        if ($this->key !== null) {
            $tags[] = self::TAG_PREFIX . ':key:' . $this->key;
        }

        return $tags;
    }
}
