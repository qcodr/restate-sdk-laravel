<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Examples\Saga;

/**
 * Shipping side of the saga: the final forward step.
 *
 * Shipping is the last action, so in this example it has no compensation of its own —
 * once a parcel is dispatched the saga has reached its happy end. (A real system might
 * model a "cancel shipment" compensation if dispatch can still be aborted.)
 */
interface ShippingService
{
    /**
     * Dispatches the order, returning a shipment id. Throws when dispatch cannot be
     * arranged, which triggers compensation of every earlier step (refund, release).
     */
    public function ship(string $orderId, string $sku, int $quantity): string;
}
