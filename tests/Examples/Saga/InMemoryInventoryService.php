<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Examples\Saga;

use InvalidArgumentException;

/**
 * A deterministic, in-memory {@see InventoryService} for the example and its tests.
 *
 * It models a warehouse ledger: stock levels per SKU plus a record of every
 * reservation so a release can be matched and made idempotent. The mutable arrays are
 * the store's own internal state (the Repository pattern) — the values it returns and
 * accepts ({@see Order}, ids) stay immutable. A monotonic counter mints reservation
 * ids so the output is reproducible given a fresh instance and a fixed call order,
 * which is exactly how the unit tests drive it.
 */
final class InMemoryInventoryService implements InventoryService
{
    /** @var array<string, int> available units keyed by SKU */
    private array $stock;

    /** @var array<string, array{sku: string, quantity: int, released: bool}> reservations keyed by id */
    private array $reservations = [];

    private int $sequence = 0;

    /**
     * @param array<string, int> $initialStock starting units keyed by SKU
     */
    public function __construct(array $initialStock = [])
    {
        $this->stock = $initialStock;
    }

    public function reserve(string $sku, int $quantity): string
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Reservation quantity must be positive');
        }

        $available = $this->stock[$sku] ?? 0;
        if ($available < $quantity) {
            throw new OutOfStockException(\sprintf(
                'Only %d unit(s) of %s in stock, %d requested',
                $available,
                $sku,
                $quantity,
            ));
        }

        $this->stock[$sku] = $available - $quantity;

        $reservationId = \sprintf('rsv-%d', ++$this->sequence);
        $this->reservations[$reservationId] = ['sku' => $sku, 'quantity' => $quantity, 'released' => false];

        return $reservationId;
    }

    public function release(string $reservationId): void
    {
        $reservation = $this->reservations[$reservationId] ?? null;
        if ($reservation === null || $reservation['released']) {
            // Idempotent: unknown or already-released reservations are a no-op, so the
            // compensation is safe to run more than once.
            return;
        }

        $this->stock[$reservation['sku']] = ($this->stock[$reservation['sku']] ?? 0) + $reservation['quantity'];
        $this->reservations[$reservationId] = [...$reservation, 'released' => true];
    }

    /** Test/inspection helper: the units currently available for a SKU. */
    public function availableStock(string $sku): int
    {
        return $this->stock[$sku] ?? 0;
    }
}
