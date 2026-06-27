<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Examples\Saga;

/**
 * Inventory side of the saga: a forward action ({@see reserve}) paired with its
 * compensation ({@see release}).
 *
 * The interface exists so the workflow depends on a contract, not a concrete store —
 * the Laravel container injects the real implementation in production while tests
 * inject an in-memory double. Keeping the durable orchestration (the workflow) and
 * the business logic (this collaborator) on opposite sides of an interface is the
 * "thin handler, fat service" rule that makes both independently testable.
 */
interface InventoryService
{
    /**
     * Reserves `$quantity` units of `$sku`, returning an opaque reservation id that
     * {@see release} later cancels. Throws when stock is insufficient so the saga can
     * decide to abort and compensate.
     */
    public function reserve(string $sku, int $quantity): string;

    /**
     * Releases a prior reservation. MUST be idempotent: the saga may replay it, and
     * releasing an unknown or already-released reservation is a harmless no-op. That
     * idempotency is what makes the compensation safe to run "at least once".
     */
    public function release(string $reservationId): void;
}
