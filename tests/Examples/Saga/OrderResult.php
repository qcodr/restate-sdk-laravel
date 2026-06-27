<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Examples\Saga;

/**
 * The immutable result the saga returns once every step has committed.
 *
 * Carrying the ids minted by each step (reservation, payment, shipment) makes the
 * happy-path outcome observable and assertable without reaching into the
 * collaborators, and `readonly` keeps the returned value tamper-proof.
 */
final class OrderResult
{
    public const STATUS_COMPLETED = 'completed';

    public function __construct(
        public readonly string $orderId,
        public readonly string $reservationId,
        public readonly string $paymentId,
        public readonly string $shipmentId,
        public readonly string $status = self::STATUS_COMPLETED,
    ) {
    }
}
