<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Examples\Saga;

use LogicException;
use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\FakeWorkflowContext;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\InMemoryInventoryService;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\InMemoryPaymentService;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\InMemoryShippingService;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\Order;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\OrderResult;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\OrderWorkflow;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\PaymentService;
use Qcodr\Restate\Sdk\Error\TerminalException;
use RuntimeException;

/**
 * The headline proof: drives {@see OrderWorkflow::run} directly through a
 * {@see FakeWorkflowContext}, with no Restate runtime, and verifies both the happy
 * path and — crucially — that a mid-saga failure triggers compensation in reverse and
 * surfaces a terminal error.
 *
 * Because `WorkflowContext` is an interface, the entire durable orchestration is unit
 * testable in-process: the fake's `run()` invokes each step's closure synchronously and
 * records the steps that committed, so the test can assert the exact saga trace.
 */
final class SagaOrchestrationTest extends TestCase
{
    /**
     * The handler receives the raw decoded body, not a typed {@see Order}, so the tests
     * drive it with the wire (array) form exactly as the Restate runtime would.
     *
     * @return array{orderId: string, customerId: string, sku: string, quantity: int, amountCents: int}
     */
    private function orderInput(int $quantity = 3): array
    {
        return (new Order('ord-1', 'cust-1', 'WIDGET', $quantity, 4999))->toArray();
    }

    /**
     * A payment gateway that always declines, throwing a plain (non-terminal) domain
     * error from `charge`. Its `refund` throws too, asserting by construction that the
     * saga never tries to refund a charge that never succeeded.
     */
    private function decliningPayment(): PaymentService
    {
        return new class () implements PaymentService {
            public function charge(string $customerId, int $amountCents): string
            {
                throw new RuntimeException('card declined');
            }

            public function refund(string $paymentId): void
            {
                throw new LogicException('refund must not run: the charge never committed');
            }
        };
    }

    public function testHappyPathRunsAllThreeStepsInOrderAndReturnsResult(): void
    {
        $inventory = new InMemoryInventoryService(['WIDGET' => 10]);
        $shipping = new InMemoryShippingService();
        $workflow = new OrderWorkflow($inventory, new InMemoryPaymentService(), $shipping);
        $ctx = new FakeWorkflowContext();

        $result = $workflow->run($ctx, $this->orderInput());

        self::assertSame('ord-1', $result->orderId);
        self::assertSame(OrderResult::STATUS_COMPLETED, $result->status);
        self::assertSame('rsv-1', $result->reservationId);
        self::assertSame('pay-1', $result->paymentId);
        self::assertSame('shp-1', $result->shipmentId);

        self::assertSame(
            ['reserve-inventory', 'charge-payment', 'ship-order'],
            $ctx->completedSteps(),
        );
    }

    public function testHappyPathDeductsStockShipsOnceAndRecordsCompletedState(): void
    {
        $inventory = new InMemoryInventoryService(['WIDGET' => 10]);
        $shipping = new InMemoryShippingService();
        $workflow = new OrderWorkflow($inventory, new InMemoryPaymentService(), $shipping);
        $ctx = new FakeWorkflowContext();

        $workflow->run($ctx, $this->orderInput());

        self::assertSame(7, $inventory->availableStock('WIDGET'));
        self::assertCount(1, $shipping->shipments());
        self::assertSame(OrderResult::STATUS_COMPLETED, $ctx->get('status'));
    }

    public function testPaymentFailureCompensatesInventoryAndNeverShips(): void
    {
        $inventory = new InMemoryInventoryService(['WIDGET' => 10]);
        $shipping = new InMemoryShippingService();
        $workflow = new OrderWorkflow($inventory, $this->decliningPayment(), $shipping);
        $ctx = new FakeWorkflowContext();

        $thrown = null;

        try {
            $workflow->run($ctx, $this->orderInput());
        } catch (TerminalException $terminal) {
            $thrown = $terminal;
        }

        // (c) the saga surfaced a terminal failure.
        self::assertInstanceOf(TerminalException::class, $thrown);

        // (a) inventory was reserved then RELEASED — net stock is back to the start.
        self::assertSame(10, $inventory->availableStock('WIDGET'));

        // (b) shipping never ran.
        self::assertSame([], $shipping->shipments());

        // The exact saga trace: reserve committed, then the compensation released it;
        // charge never completed and ship was never reached.
        self::assertSame(
            ['reserve-inventory', 'release-inventory'],
            $ctx->completedSteps(),
        );

        // The durable state machine ends in the rolled-back state.
        self::assertSame('rolled_back', $ctx->get('status'));
    }

    public function testPaymentFailureSurfacesTerminalExceptionWrappingTheCause(): void
    {
        $workflow = new OrderWorkflow(
            new InMemoryInventoryService(['WIDGET' => 10]),
            $this->decliningPayment(),
            new InMemoryShippingService(),
        );

        $this->expectException(TerminalException::class);
        $this->expectExceptionMessage('Order ord-1 rolled back after a step failed: card declined');

        $workflow->run(new FakeWorkflowContext(), $this->orderInput());
    }

    public function testFirstStepFailureFailsFastWithNoCompensation(): void
    {
        // Only one unit in stock but the order wants three: the very first step fails,
        // so there is nothing to compensate and nothing downstream runs.
        $inventory = new InMemoryInventoryService(['WIDGET' => 1]);
        $shipping = new InMemoryShippingService();
        $workflow = new OrderWorkflow($inventory, new InMemoryPaymentService(), $shipping);
        $ctx = new FakeWorkflowContext();

        $thrown = null;

        try {
            $workflow->run($ctx, $this->orderInput(3));
        } catch (TerminalException $terminal) {
            $thrown = $terminal;
        }

        self::assertInstanceOf(TerminalException::class, $thrown);
        self::assertSame([], $ctx->completedSteps());
        self::assertSame([], $shipping->shipments());
        self::assertSame(1, $inventory->availableStock('WIDGET'));
        self::assertSame('rolled_back', $ctx->get('status'));
    }

    public function testInvalidInputFailsTerminallyWithBadRequestAndRunsNoSteps(): void
    {
        // The runtime hands the handler a decoded array; a malformed one (here: no `sku`)
        // is a permanent client error. It must surface as a terminal HTTP 400 BEFORE any
        // step runs — so there is nothing to compensate and no side effect to undo.
        $inventory = new InMemoryInventoryService(['WIDGET' => 10]);
        $shipping = new InMemoryShippingService();
        $workflow = new OrderWorkflow($inventory, new InMemoryPaymentService(), $shipping);
        $ctx = new FakeWorkflowContext();

        $thrown = null;

        try {
            $workflow->run($ctx, ['orderId' => 'ord-1', 'customerId' => 'cust-1', 'quantity' => 2, 'amountCents' => 4999]);
        } catch (TerminalException $terminal) {
            $thrown = $terminal;
        }

        self::assertInstanceOf(TerminalException::class, $thrown);
        self::assertSame(400, $thrown->statusCode());
        self::assertSame([], $ctx->completedSteps());
        self::assertSame([], $shipping->shipments());
        self::assertSame(10, $inventory->availableStock('WIDGET'));
    }
}
