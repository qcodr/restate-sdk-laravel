# Durable Saga: order processing with compensation

A **saga** is a multi-step business process where each forward step has a matching
**compensation** (its undo). If a later step fails, the saga runs the compensations for
the steps that already committed — in reverse — so the system is left consistent.

This recipe builds an order saga: **reserve inventory → charge payment → ship**. If any
step fails, it **refunds the payment** and **releases the inventory**, then fails
terminally.

> Full, runnable source lives in `tests/Examples/Saga/` with tests in
> `tests/Unit/Examples/Saga/`.

## When to use this (vs chained Laravel jobs)

| You have… | Chained jobs (`Bus::chain`, queued listeners) | Restate saga |
|-----------|-----------------------------------------------|--------------|
| A crash between "charged" and "shipped" | You re-run the chain and risk **double-charging**, or hand-roll an idempotency table | `ctx->run()` journals each step; replay returns the **stored** result — exactly once |
| A mid-process failure | You write a bespoke rollback job and a state column to know how far you got | Compensations are part of the workflow; the journal *is* the progress |
| Long waits / retries | Backoff and dead-letter plumbing | Automatic retries with durable timers, survives deploys/restarts |
| Observability | A status column you maintain by hand | Durable workflow state (`ctx->set`) + the Restate UI |

Reach for the saga when **partial failure must be undone** and **steps must not repeat**.
For fire-and-forget or naturally idempotent fan-out, a plain queued job is fine.

## How compensation works

Each forward step pushes its undo onto a LIFO stack as soon as it commits. On failure
the `catch` pops the stack and runs the undos **newest-first**, each as its own
`ctx->run()` durable step, then throws a `TerminalException` so the runtime stops
retrying an order that has already been rolled back.

```
reserve ✓ → push release          charge ✗  ──► compensate: release           → TerminalException
charge  ✓ → push refund           ship   ✗  ──► compensate: refund, release   → TerminalException
ship    ✓ → (no compensation)     done      ──► OrderResult
```

Because a compensation may itself be replayed after a crash, **every compensation must
be idempotent** (releasing an unknown reservation or refunding a settled payment is a
no-op). In production, also make compensations resilient to *their own* failure (alert /
dead-letter), since a failed rollback needs human attention.

## The workflow

A thin, stateless `#[Workflow]` that orchestrates injected collaborators. Every
non-deterministic effect is wrapped in `ctx->run()`; per-invocation data stays in locals.

```php
#[Workflow]
final class OrderWorkflow
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PaymentService $payment,
        private readonly ShippingService $shipping,
    ) {}

    #[Handler]
    public function run(WorkflowContext $ctx, ?array $input = null): OrderResult
    {
        // The SDK's JSON serde hands the handler the decoded *array*, not a hydrated
        // object (a typed `Order $order` param would get an array and fail forever). Build
        // and validate the value object at the boundary; invalid input is a terminal 400,
        // thrown before any step runs, so there is nothing to compensate.
        $order = Order::fromArray($input ?? []);

        /** @var list<array{name: string, undo: \Closure(): void}> $compensations */
        $compensations = [];

        try {
            $ctx->set('status', 'reserving');
            $reservationId = $ctx->run(
                'reserve-inventory',
                fn (): string => $this->inventory->reserve($order->sku, $order->quantity),
            );
            $compensations[] = ['name' => 'release-inventory',
                'undo' => fn (): void => $this->inventory->release($reservationId)];

            $ctx->set('status', 'charging');
            $paymentId = $ctx->run(
                'charge-payment',
                fn (): string => $this->payment->charge($order->customerId, $order->amountCents),
            );
            $compensations[] = ['name' => 'refund-payment',
                'undo' => fn (): void => $this->payment->refund($paymentId)];

            $ctx->set('status', 'shipping');
            $shipmentId = $ctx->run(
                'ship-order',
                fn (): string => $this->shipping->ship($order->orderId, $order->sku, $order->quantity),
            );

            $ctx->set('status', 'completed');

            return new OrderResult($order->orderId, $reservationId, $paymentId, $shipmentId);
        } catch (\Throwable $failure) {
            $ctx->set('status', 'compensating');
            foreach (\array_reverse($compensations) as $c) {
                $ctx->run($c['name'], $c['undo']);   // undo newest-first, durably
            }
            $ctx->set('status', 'rolled_back');

            throw $failure instanceof TerminalException
                ? $failure
                : new TerminalException(
                    \sprintf('Order %s rolled back after a step failed: %s', $order->orderId, $failure->getMessage()),
                    previous: $failure,
                );
        }
    }
}
```

The collaborators (`InventoryService`, `PaymentService`, `ShippingService`) are ordinary
interfaces with idempotent implementations — the real, unit-testable business logic. The
workflow only orchestrates; this is the "thin handler, fat service" split.

## Register it

Add the workflow class to `config/restate.php`; its constructor dependencies are resolved
from the Laravel container, so bind the interfaces to implementations as usual:

```php
// config/restate.php
'services' => [
    App\Restate\OrderWorkflow::class,
],

// e.g. in a service provider
$this->app->bind(InventoryService::class, DatabaseInventoryService::class);
$this->app->bind(PaymentService::class, StripePaymentService::class);
$this->app->bind(ShippingService::class, CarrierShippingService::class);
```

## Invoke it

Register the deployment, then start the workflow through the ingress. A workflow is keyed
— use the order id as the key so each order runs exactly once:

```bash
restate deployments register http://localhost:8000/restate --use-http1.1

# POST to /{Workflow}/{key}/{handler}
curl localhost:8080/OrderWorkflow/ord-123/run \
  -H 'content-type: application/json' \
  -d '{"orderId":"ord-123","customerId":"cust-1","sku":"WIDGET","quantity":3,"amountCents":4999}'
```

On success you get the `OrderResult`; on a step failure the call returns a terminal error
and the journal shows the reserve/charge that committed alongside the compensations that
undid them.

## Test it without a runtime

`WorkflowContext` is an interface, so the whole saga is unit-testable in-process with a
fake context whose `run($name, $fn)` just calls `$fn()` and records the step name. The
compensation test injects a payment stub that throws on `charge` and asserts the trace is
exactly `['reserve-inventory', 'release-inventory']`, that stock returned to its starting
level, that shipping never ran, and that a `TerminalException` surfaced. See
`tests/Unit/Examples/Saga/SagaOrchestrationTest.php` and the `FakeWorkflowContext` in
`tests/Examples/Saga/`.
```bash
vendor/bin/phpunit --filter Saga
```
