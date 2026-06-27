<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Client;

use RuntimeException;

/**
 * Thrown when the Restate ingress rejects (or mis-answers) an invocation dispatched by
 * {@see RestateClient}.
 *
 * It carries the raw HTTP {@see self::$status} and {@see self::$responseBody} so callers can
 * branch on them — e.g. surface a 429 from a rate-limited handler, or log the ingress error
 * envelope — instead of parsing a stringly-typed message. The exception is intentionally a
 * {@see RuntimeException} (an unchecked, programmer-or-environment fault): a non-2xx ingress
 * response is not part of a handler's normal return contract.
 *
 * The body is only summarised (trimmed and length-capped) into the human-readable message so
 * a multi-megabyte error payload never bloats logs; the full body stays available verbatim on
 * {@see self::$responseBody}.
 */
final class RestateRequestException extends RuntimeException
{
    /**
     * Upper bound on how much of the response body is inlined into the exception message. The
     * full, untruncated body remains on {@see self::$responseBody}.
     */
    private const BODY_SNIPPET_LIMIT = 500;

    public function __construct(
        public readonly int $status,
        public readonly string $responseBody,
        string $message,
    ) {
        // Mirror the HTTP status onto the exception code so `getCode()` is meaningful to
        // generic handlers/loggers that never reach for the typed property.
        parent::__construct($message, $status);
    }

    /**
     * The ingress answered an invocation with a non-2xx status (the handler failed, the
     * service/handler is unknown, auth was rejected, …).
     */
    public static function forFailedRequest(string $path, int $status, string $body): self
    {
        return new self($status, $body, \sprintf(
            'Restate ingress request to "%s" failed with HTTP %d: %s',
            $path,
            $status,
            self::summarise($body),
        ));
    }

    /**
     * A one-way send was accepted (2xx) but the response did not carry the `invocationId`
     * field the ingress is documented to return — an unexpected envelope we refuse to guess at.
     */
    public static function forUnexpectedSendResponse(string $path, int $status, string $body): self
    {
        return new self($status, $body, \sprintf(
            'Restate ingress send to "%s" returned HTTP %d without a string "invocationId": %s',
            $path,
            $status,
            self::summarise($body),
        ));
    }

    /**
     * Collapse a raw body into a short, log-safe snippet for the exception message.
     */
    private static function summarise(string $body): string
    {
        $trimmed = \trim($body);
        if ($trimmed === '') {
            return '<empty body>';
        }

        if (\strlen($trimmed) <= self::BODY_SNIPPET_LIMIT) {
            return $trimmed;
        }

        return \substr($trimmed, 0, self::BODY_SNIPPET_LIMIT) . '…';
    }
}
