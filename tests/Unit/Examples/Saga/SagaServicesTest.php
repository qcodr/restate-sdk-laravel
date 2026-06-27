<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Examples\Saga;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\InMemoryInventoryService;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\InMemoryPaymentService;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\InMemoryShippingService;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\OutOfStockException;

/**
 * Unit-tests the pure saga collaborators in isolation — no workflow, no context.
 *
 * These hold the real business logic, so the durability layer (the workflow) can stay
 * a thin orchestrator. The emphasis is the property the saga depends on for safe
 * rollback: every compensation ({@see InMemoryInventoryService::release},
 * {@see InMemoryPaymentService::refund}) is idempotent.
 */
final class SagaServicesTest extends TestCase
{
    public function testReserveReducesAvailableStockAndReturnsAnId(): void
    {
        $inventory = new InMemoryInventoryService(['WIDGET' => 10]);

        $reservationId = $inventory->reserve('WIDGET', 4);

        self::assertSame('rsv-1', $reservationId);
        self::assertSame(6, $inventory->availableStock('WIDGET'));
    }

    public function testReserveThrowsWhenStockIsInsufficient(): void
    {
        $inventory = new InMemoryInventoryService(['WIDGET' => 2]);

        $this->expectException(OutOfStockException::class);

        $inventory->reserve('WIDGET', 5);
    }

    public function testReserveRejectsNonPositiveQuantity(): void
    {
        $inventory = new InMemoryInventoryService(['WIDGET' => 5]);

        $this->expectException(InvalidArgumentException::class);

        $inventory->reserve('WIDGET', 0);
    }

    public function testReleaseRestoresStockAndIsIdempotent(): void
    {
        $inventory = new InMemoryInventoryService(['WIDGET' => 10]);
        $reservationId = $inventory->reserve('WIDGET', 4);
        self::assertSame(6, $inventory->availableStock('WIDGET'));

        $inventory->release($reservationId);
        self::assertSame(10, $inventory->availableStock('WIDGET'));

        // Releasing the same reservation again must NOT double-credit the stock.
        $inventory->release($reservationId);
        self::assertSame(10, $inventory->availableStock('WIDGET'));
    }

    public function testReleaseOfUnknownReservationIsANoOp(): void
    {
        $inventory = new InMemoryInventoryService(['WIDGET' => 10]);

        $inventory->release('rsv-does-not-exist');

        self::assertSame(10, $inventory->availableStock('WIDGET'));
    }

    public function testChargeReturnsAnIdAndRefundIsIdempotent(): void
    {
        $payment = new InMemoryPaymentService();

        $paymentId = $payment->charge('cust-1', 4999);
        self::assertSame('pay-1', $paymentId);
        self::assertFalse($payment->isRefunded($paymentId));

        $payment->refund($paymentId);
        self::assertTrue($payment->isRefunded($paymentId));

        // Refunding again is a harmless no-op.
        $payment->refund($paymentId);
        self::assertTrue($payment->isRefunded($paymentId));
    }

    public function testRefundOfUnknownPaymentIsANoOp(): void
    {
        $payment = new InMemoryPaymentService();

        $payment->refund('pay-unknown');

        self::assertFalse($payment->isRefunded('pay-unknown'));
    }

    public function testChargeRejectsNonPositiveAmount(): void
    {
        $payment = new InMemoryPaymentService();

        $this->expectException(InvalidArgumentException::class);

        $payment->charge('cust-1', 0);
    }

    public function testShipRecordsTheShipmentAndReturnsAnId(): void
    {
        $shipping = new InMemoryShippingService();

        $shipmentId = $shipping->ship('ord-1', 'WIDGET', 2);

        self::assertSame('shp-1', $shipmentId);
        self::assertCount(1, $shipping->shipments());
    }
}
