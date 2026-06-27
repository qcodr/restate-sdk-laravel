<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Examples\Saga;

/**
 * Payment side of the saga: {@see charge} commits money, {@see refund} gives it back.
 *
 * As with {@see InventoryService}, the workflow depends on this contract so the real
 * gateway (Stripe, etc.) and the test double are interchangeable.
 */
interface PaymentService
{
    /**
     * Charges the customer `$amountCents`, returning a payment id that {@see refund}
     * can later reverse. Throws when the charge is declined; the saga treats that as a
     * terminal step failure and rolls back.
     */
    public function charge(string $customerId, int $amountCents): string;

    /**
     * Refunds a prior charge. MUST be idempotent: refunding an unknown or
     * already-refunded payment is a no-op, so the compensation is safe to retry.
     */
    public function refund(string $paymentId): void;
}
