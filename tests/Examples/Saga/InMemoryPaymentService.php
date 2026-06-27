<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Examples\Saga;

use InvalidArgumentException;

/**
 * A deterministic, in-memory {@see PaymentService} for the example and its tests.
 *
 * It keeps a ledger of charges so a refund can be matched and made idempotent. Like
 * the inventory store, the mutable array is internal bookkeeping; the ids it hands
 * back are immutable values. The always-succeeds behaviour models a healthy gateway —
 * the failure path is exercised by a throwing stub in the orchestration test, keeping
 * this happy-path collaborator simple.
 */
final class InMemoryPaymentService implements PaymentService
{
    /** @var array<string, array{customerId: string, amountCents: int, refunded: bool}> charges keyed by id */
    private array $payments = [];

    private int $sequence = 0;

    public function charge(string $customerId, int $amountCents): string
    {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Charge amount must be positive');
        }

        $paymentId = \sprintf('pay-%d', ++$this->sequence);
        $this->payments[$paymentId] = ['customerId' => $customerId, 'amountCents' => $amountCents, 'refunded' => false];

        return $paymentId;
    }

    public function refund(string $paymentId): void
    {
        $payment = $this->payments[$paymentId] ?? null;
        if ($payment === null || $payment['refunded']) {
            // Idempotent: refunding an unknown or already-refunded charge is a no-op.
            return;
        }

        $this->payments[$paymentId] = [...$payment, 'refunded' => true];
    }

    /** Test/inspection helper: whether a charge has been refunded. */
    public function isRefunded(string $paymentId): bool
    {
        return $this->payments[$paymentId]['refunded'] ?? false;
    }
}
