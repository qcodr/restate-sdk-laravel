<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Examples\Saga;

use Closure;
use Qcodr\Restate\Sdk\Context\WorkflowContext;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Workflow;
use Throwable;

/**
 * A durable order-processing saga: reserve inventory -> charge payment -> ship.
 *
 * WHY a workflow and not a chain of Laravel jobs: each step here is a *durable* side
 * effect. `$ctx->run('step', ...)` executes the closure once, journals its result, and
 * on any retry replays the stored result instead of re-running it — so a crash between
 * "charged" and "shipped" never double-charges and never loses the shipment. A chained
 * job queue gives you none of that exactly-once + automatic-compensation guarantee for
 * free; you would hand-roll idempotency keys, a state table, and a rollback worker.
 *
 * Saga = forward steps each paired with a compensation. As every forward step commits
 * we push its undo action onto a stack; if a later step throws we pop the stack and run
 * the compensations in REVERSE order (so the most recent commit is undone first), then
 * surface a {@see TerminalException} so the runtime stops retrying an order we have
 * already rolled back.
 *
 * Handler discipline (the one rule): this class is stateless. Every non-deterministic
 * effect lives inside a `$ctx->run(...)`; per-invocation data lives in locals; the
 * collaborators are injected, never mutated. The constructor dependencies are resolved
 * from the Laravel container in production and supplied directly in tests.
 */
#[Workflow]
final class OrderWorkflow
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PaymentService $payment,
        private readonly ShippingService $shipping,
    ) {
    }

    /**
     * The `run` handler: executes exactly once per workflow key (the order id).
     *
     * The input is the raw decoded body (`?array`), NOT a typed {@see Order}: the SDK's
     * JSON serde does not hydrate custom classes, so a typed parameter would receive an
     * array at runtime and fail forever (see {@see Order} for the full explanation). We
     * build and validate the {@see Order} here at the boundary; an empty body decodes to
     * null, which {@see Order::fromArray} rejects as a terminal 400. Validation runs
     * before the try block, so a malformed order fails fast with nothing to compensate.
     *
     * Names of the durable steps double as their journal keys, so they must stay stable
     * across deploys — renaming a step orphans its journal entry.
     *
     * @param array<string, mixed>|null $input the decoded JSON request body
     */
    #[Handler]
    public function run(WorkflowContext $ctx, ?array $input = null): OrderResult
    {
        $order = Order::fromArray($input ?? []);

        // LIFO stack of compensations, each tagged with its own durable-step name. A
        // local — never instance state — so concurrent invocations never share it.
        /** @var list<array{name: string, undo: Closure(): void}> $compensations */
        $compensations = [];

        try {
            $ctx->set('status', 'reserving');
            $reservationId = $ctx->run(
                'reserve-inventory',
                fn (): string => $this->inventory->reserve($order->sku, $order->quantity),
            );
            $compensations[] = [
                'name' => 'release-inventory',
                'undo' => function () use ($reservationId): void {
                    $this->inventory->release($reservationId);
                },
            ];
            $ctx->set('reservationId', $reservationId);

            $ctx->set('status', 'charging');
            $paymentId = $ctx->run(
                'charge-payment',
                fn (): string => $this->payment->charge($order->customerId, $order->amountCents),
            );
            $compensations[] = [
                'name' => 'refund-payment',
                'undo' => function () use ($paymentId): void {
                    $this->payment->refund($paymentId);
                },
            ];
            $ctx->set('paymentId', $paymentId);

            $ctx->set('status', 'shipping');
            $shipmentId = $ctx->run(
                'ship-order',
                fn (): string => $this->shipping->ship($order->orderId, $order->sku, $order->quantity),
            );
            $ctx->set('shipmentId', $shipmentId);

            $ctx->set('status', OrderResult::STATUS_COMPLETED);

            return new OrderResult($order->orderId, $reservationId, $paymentId, $shipmentId);
        } catch (Throwable $failure) {
            // A step failed. Undo everything that already committed, in reverse, then
            // fail terminally so Restate does not retry an order we have rolled back.
            $ctx->set('status', 'compensating');
            $this->compensate($ctx, $compensations);
            $ctx->set('status', 'rolled_back');

            throw $this->asTerminal($order, $failure);
        }
    }

    /**
     * Runs the accumulated compensations newest-first, each as its own durable step so a
     * crash mid-rollback replays the already-finished undos from the journal rather than
     * repeating them. Compensations are idempotent (see the service contracts), so even
     * an at-least-once replay is safe.
     *
     * @param list<array{name: string, undo: Closure(): void}> $compensations
     */
    private function compensate(WorkflowContext $ctx, array $compensations): void
    {
        foreach (\array_reverse($compensations) as $compensation) {
            $ctx->run($compensation['name'], $compensation['undo']);
        }
    }

    /**
     * Translates a step failure into the saga's terminal outcome. A failure that is
     * already terminal keeps its original code/metadata; any other throwable (a plain
     * domain exception from a collaborator) is wrapped so the caller always sees a
     * single, deterministic terminal result for a rolled-back order.
     */
    private function asTerminal(Order $order, Throwable $failure): TerminalException
    {
        if ($failure instanceof TerminalException) {
            return $failure;
        }

        return new TerminalException(
            \sprintf('Order %s rolled back after a step failed: %s', $order->orderId, $failure->getMessage()),
            previous: $failure,
        );
    }
}
