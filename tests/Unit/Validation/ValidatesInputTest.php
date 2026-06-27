<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Validation;

use Qcodr\Restate\Laravel\Tests\TestCase;
use Qcodr\Restate\Laravel\Tests\Validation\OrderInputHandler;
use Qcodr\Restate\Laravel\Validation\RestateValidationException;
use Qcodr\Restate\Sdk\Error\TerminalException;

/**
 * Proves the boundary contract of {@see ValidatesInput}: valid input yields the validated
 * subset, and any malformed input fails as a terminal HTTP 400 carrying useful messages.
 *
 * It extends the package Testbench {@see TestCase} because Laravel's `Validator` resolves
 * its factory and translator from the container — the validation cannot run without a
 * booted framework.
 */
final class ValidatesInputTest extends TestCase
{
    private OrderInputHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new OrderInputHandler();
    }

    public function testReturnsValidatedDataForValidInput(): void
    {
        $data = $this->handler->create(['orderId' => 'ord-1', 'quantity' => 3]);

        self::assertSame(['orderId' => 'ord-1', 'quantity' => 3], $data);
    }

    public function testStripsKeysThatHaveNoRule(): void
    {
        $data = $this->handler->create([
            'orderId' => 'ord-1',
            'quantity' => 3,
            'isAdmin' => true,     // unlisted — must never reach the durable logic
            'totalCents' => 9999,
        ]);

        self::assertSame(['orderId' => 'ord-1', 'quantity' => 3], $data);
        self::assertArrayNotHasKey('isAdmin', $data);
        self::assertArrayNotHasKey('totalCents', $data);
    }

    public function testThrowsTerminalBadRequestWhenRequiredFieldIsMissing(): void
    {
        $thrown = $this->captureFailure(['quantity' => 3]); // no orderId

        // RestateValidationException extends the SDK's TerminalException, so the runtime
        // treats a validation failure as a non-retryable terminal 400 (not retried forever).
        self::assertInstanceOf(RestateValidationException::class, $thrown);
        self::assertSame(400, $thrown->statusCode());
        self::assertArrayHasKey('orderId', $thrown->errors());
    }

    public function testNullEmptyBodyFailsRequiredRules(): void
    {
        $thrown = $this->captureFailure(null);

        self::assertInstanceOf(RestateValidationException::class, $thrown);
        self::assertSame(400, $thrown->statusCode());
        self::assertArrayHasKey('orderId', $thrown->errors());
        self::assertArrayHasKey('quantity', $thrown->errors());
    }

    public function testIntegerRuleRejectsNonIntegerValue(): void
    {
        $thrown = $this->captureFailure(['orderId' => 'ord-1', 'quantity' => 'lots']);

        self::assertInstanceOf(RestateValidationException::class, $thrown);
        self::assertArrayHasKey('quantity', $thrown->errors());
    }

    public function testMinRuleRejectsValueBelowThreshold(): void
    {
        $thrown = $this->captureFailure(['orderId' => 'ord-1', 'quantity' => 0]);

        self::assertInstanceOf(RestateValidationException::class, $thrown);
        self::assertArrayHasKey('quantity', $thrown->errors());
    }

    public function testMetadataCarriesPerFieldMessages(): void
    {
        $thrown = $this->captureFailure(['quantity' => 0]); // orderId missing, quantity < min

        self::assertInstanceOf(RestateValidationException::class, $thrown);

        // `metadata` is the string=>string channel the runtime ships back to the caller.
        self::assertArrayHasKey('orderId', $thrown->metadata);
        self::assertArrayHasKey('quantity', $thrown->metadata);
        self::assertNotSame('', $thrown->metadata['orderId']);
        // The rule name surfaces in the message whether or not lang files are present,
        // so the assertion proves a real message reached the metadata either way.
        self::assertStringContainsStringIgnoringCase('required', $thrown->metadata['orderId']);
        self::assertStringContainsStringIgnoringCase('required', $thrown->getMessage());
    }

    /**
     * Drives the fixture handler and returns the thrown validation exception, keeping the
     * try/catch boilerplate out of each test.
     *
     * @param array<string, mixed>|null $input
     */
    private function captureFailure(?array $input): ?RestateValidationException
    {
        try {
            $this->handler->create($input);
        } catch (RestateValidationException $exception) {
            return $exception;
        }

        return null;
    }
}
