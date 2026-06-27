<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Examples\Saga;

/**
 * A deterministic, in-memory {@see ShippingService} for the example and its tests.
 *
 * It records every dispatch so tests can assert both the happy path (a parcel was
 * shipped exactly once) and the compensation path (shipping was NEVER reached because
 * an earlier step failed first).
 */
final class InMemoryShippingService implements ShippingService
{
    /** @var list<array{orderId: string, sku: string, quantity: int, shipmentId: string}> */
    private array $shipments = [];

    private int $sequence = 0;

    public function ship(string $orderId, string $sku, int $quantity): string
    {
        $shipmentId = \sprintf('shp-%d', ++$this->sequence);
        $this->shipments[] = [
            'orderId' => $orderId,
            'sku' => $sku,
            'quantity' => $quantity,
            'shipmentId' => $shipmentId,
        ];

        return $shipmentId;
    }

    /**
     * Test/inspection helper: the dispatches recorded so far.
     *
     * @return list<array{orderId: string, sku: string, quantity: int, shipmentId: string}>
     */
    public function shipments(): array
    {
        return $this->shipments;
    }
}
