<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Client;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Laravel\Client\RestateRequestException;

/**
 * Pins the body-summarising contract of {@see RestateRequestException}: the raw response body is
 * trimmed, an empty/whitespace body becomes a placeholder, a body within the snippet limit is
 * inlined verbatim, and an over-long body is cut to the limit with a trailing ellipsis — all
 * while the full body stays verbatim on {@see RestateRequestException::$responseBody}. These are
 * pure transformations, so they are exercised directly through the public factory without an
 * HTTP layer.
 */
final class RestateRequestExceptionTest extends TestCase
{
    private const SNIPPET_LIMIT = 500;

    public function testCarriesStatusAndFullBodyVerbatim(): void
    {
        $exception = RestateRequestException::forFailedRequest('/Svc/h', 503, 'service unavailable');

        self::assertSame(503, $exception->status);
        self::assertSame(503, $exception->getCode());
        self::assertSame('service unavailable', $exception->responseBody);
    }

    public function testInlinesAShortBodyVerbatimInTheMessage(): void
    {
        $exception = RestateRequestException::forFailedRequest('/Svc/h', 429, 'rate limit exceeded');

        self::assertStringContainsString('failed with HTTP 429', $exception->getMessage());
        self::assertStringContainsString('rate limit exceeded', $exception->getMessage());
    }

    public function testTrimsSurroundingWhitespaceFromTheSnippet(): void
    {
        $exception = RestateRequestException::forFailedRequest('/Svc/h', 400, '  bad input  ');

        self::assertStringContainsString(': bad input', $exception->getMessage());
        self::assertStringNotContainsString('bad input  ', $exception->getMessage());
    }

    public function testReplacesAnEmptyBodyWithAPlaceholder(): void
    {
        $exception = RestateRequestException::forFailedRequest('/Svc/h', 500, '');

        self::assertStringContainsString('<empty body>', $exception->getMessage());
    }

    public function testReplacesAWhitespaceOnlyBodyWithAPlaceholder(): void
    {
        $exception = RestateRequestException::forFailedRequest('/Svc/h', 500, "   \n\t ");

        self::assertStringContainsString('<empty body>', $exception->getMessage());
    }

    public function testInlinesABodyExactlyAtTheLimitVerbatim(): void
    {
        $body = \str_repeat('a', self::SNIPPET_LIMIT);

        $exception = RestateRequestException::forFailedRequest('/Svc/h', 502, $body);

        self::assertStringContainsString($body, $exception->getMessage());
        self::assertStringNotContainsString('…', $exception->getMessage());
    }

    public function testTruncatesAnOverLongBodyToTheLimitWithAnEllipsis(): void
    {
        $body = \str_repeat('a', 600);
        $expectedSnippet = \str_repeat('a', self::SNIPPET_LIMIT) . '…';

        $exception = RestateRequestException::forFailedRequest('/Svc/h', 500, $body);

        self::assertStringContainsString($expectedSnippet, $exception->getMessage());
        // The full 600-char body must not survive into the message, only its 500-char head.
        self::assertStringNotContainsString($body, $exception->getMessage());
        self::assertSame($body, $exception->responseBody);
    }
}
