<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Examples\Saga;

use Qcodr\Restate\Sdk\Error\TerminalException;

/**
 * The immutable input to the order-processing saga.
 *
 * It is `readonly` because the workflow input is a value, not a mutable record: the same
 * object replays identically on every attempt, so making it impossible to mutate removes
 * a whole class of "the second attempt saw different data" bugs.
 *
 * IMPORTANT — why the handler does NOT type-hint this class directly: the SDK's
 * {@see \Qcodr\Restate\Sdk\Serde\JsonSerde} only coerces *scalar* type hints. For a JSON
 * object body it hands the handler the decoded associative **array** as-is — it does not
 * hydrate custom classes. A handler declaring `Order $order` would therefore receive an
 * `array` and fail with a `TypeError` on every attempt (a retryable error, so it would
 * loop forever). The workflow instead accepts the raw array and calls {@see fromArray} at
 * the boundary, which both builds the value object and validates the request.
 */
final class Order
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly string $sku,
        public readonly int $quantity,
        public readonly int $amountCents,
    ) {
    }

    /**
     * Builds a validated {@see Order} from the decoded request body.
     *
     * A malformed order is a permanent client error, not a transient one, so invalid or
     * missing fields raise a {@see TerminalException} with HTTP 400 — the runtime returns
     * it to the caller and does NOT retry. Validating here (the system boundary) keeps the
     * rest of the saga free to assume a well-formed {@see Order}.
     *
     * @param array<string, mixed> $input the decoded JSON request body
     */
    public static function fromArray(array $input): self
    {
        $orderId = self::requireString($input, 'orderId');
        $customerId = self::requireString($input, 'customerId');
        $sku = self::requireString($input, 'sku');
        $quantity = self::requireInt($input, 'quantity');
        $amountCents = self::requireInt($input, 'amountCents');

        if ($quantity <= 0) {
            throw self::invalid('"quantity" must be greater than zero');
        }
        if ($amountCents < 0) {
            throw self::invalid('"amountCents" must not be negative');
        }

        return new self($orderId, $customerId, $sku, $quantity, $amountCents);
    }

    /**
     * The wire form: the associative array the SDK serialises onto the request body. Used
     * by tests to drive the handler exactly as the runtime would.
     *
     * @return array{orderId: string, customerId: string, sku: string, quantity: int, amountCents: int}
     */
    public function toArray(): array
    {
        return [
            'orderId' => $this->orderId,
            'customerId' => $this->customerId,
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'amountCents' => $this->amountCents,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function requireString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            throw self::invalid(\sprintf('missing or invalid "%s" (expected a non-empty string)', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function requireInt(array $input, string $key): int
    {
        $value = $input[$key] ?? null;
        if (!\is_int($value)) {
            throw self::invalid(\sprintf('missing or invalid "%s" (expected an integer)', $key));
        }

        return $value;
    }

    private static function invalid(string $detail): TerminalException
    {
        return new TerminalException('Invalid order input: ' . $detail, 400);
    }
}
