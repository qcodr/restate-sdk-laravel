<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Examples\Saga;

use BadMethodCallException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qcodr\Restate\Sdk\Context\Awakeable;
use Qcodr\Restate\Sdk\Context\CallHandle;
use Qcodr\Restate\Sdk\Context\ContextRand;
use Qcodr\Restate\Sdk\Context\DurableFuture;
use Qcodr\Restate\Sdk\Context\RunOptions;
use Qcodr\Restate\Sdk\Context\TraceContext;
use Qcodr\Restate\Sdk\Context\WorkflowContext;

/**
 * An in-memory test double for {@see WorkflowContext}.
 *
 * `WorkflowContext` is a plain PHP interface, so the saga can be driven entirely in a
 * unit test without a Restate runtime, a real journal, or any network — we just supply
 * a fake context. This double models the only two context features the saga relies on:
 *
 *   - {@see run}: executes the closure immediately and, on success, records the step
 *     name. Recording *after* the closure returns means a step whose closure throws is
 *     NOT recorded, so {@see completedSteps} reads as an honest trace of what actually
 *     committed — including the compensation steps, which are themselves `run` calls.
 *   - workflow state ({@see set} / {@see get} / {@see stateKeys} / {@see clear} /
 *     {@see clearAll}): backed by a simple associative array.
 *
 * Everything else on the interface — durable timers, service/object/workflow calls,
 * awakeables, signals, durable promises, randomness — is a distributed primitive that
 * cannot be faithfully faked in-process, so each throws {@see BadMethodCallException}.
 * That is deliberate: if the workflow under test ever reaches for one of those, the
 * test fails loudly instead of silently returning a wrong value. The saga never does,
 * which is what makes this minimal double sufficient.
 */
final class FakeWorkflowContext implements WorkflowContext
{
    /** @var array<string, mixed> the workflow's key/value state */
    private array $state = [];

    /** @var list<string> names of the durable steps that ran to completion, in order */
    private array $completedSteps = [];

    public function __construct(private readonly string $key = 'order-key')
    {
    }

    /**
     * Records and returns the durable steps that completed successfully, in execution
     * order (forward steps followed by any compensations).
     *
     * @return list<string>
     */
    public function completedSteps(): array
    {
        return $this->completedSteps;
    }

    // --- the subset of the context the saga actually uses ---------------------

    public function run(string $name, callable $action, ?RunOptions $options = null): mixed
    {
        // Run the side effect, then record it as committed only if it did not throw —
        // mirroring a real journal entry, which is written once the step succeeds.
        $result = $action();
        $this->completedSteps[] = $name;

        return $result;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function get(string $key): mixed
    {
        return $this->state[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public function stateKeys(): array
    {
        return \array_keys($this->state);
    }

    public function set(string $key, mixed $value): void
    {
        $this->state[$key] = $value;
    }

    public function clear(string $key): void
    {
        unset($this->state[$key]);
    }

    public function clearAll(): void
    {
        $this->state = [];
    }

    public function logger(): LoggerInterface
    {
        return new NullLogger();
    }

    public function invocationId(): string
    {
        return 'inv-fake';
    }

    /**
     * @return array<string, string>
     */
    public function requestHeaders(): array
    {
        return [];
    }

    public function requestIdempotencyKey(): ?string
    {
        return null;
    }

    public function traceContext(): ?TraceContext
    {
        return null;
    }

    // --- distributed primitives the in-memory double intentionally rejects ----

    public function runAsync(string $name, callable $action): DurableFuture
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function sleep(float $seconds): void
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function timer(float $seconds): DurableFuture
    {
        throw $this->unsupported(__FUNCTION__);
    }

    /** @param array<string, string> $headers */
    public function serviceCall(
        string $service,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): mixed {
        throw $this->unsupported(__FUNCTION__);
    }

    /** @param array<string, string> $headers */
    public function objectCall(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): mixed {
        throw $this->unsupported(__FUNCTION__);
    }

    /** @param array<string, string> $headers */
    public function workflowCall(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): mixed {
        throw $this->unsupported(__FUNCTION__);
    }

    public function genericCall(
        string $service,
        string $key,
        string $handler,
        string $parameter,
        ?string $idempotencyKey = null,
    ): string {
        throw $this->unsupported(__FUNCTION__);
    }

    /** @param array<string, string> $headers */
    public function serviceCallAsync(
        string $service,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): DurableFuture {
        throw $this->unsupported(__FUNCTION__);
    }

    /** @param array<string, string> $headers */
    public function objectCallAsync(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): DurableFuture {
        throw $this->unsupported(__FUNCTION__);
    }

    /** @param array<string, string> $headers */
    public function workflowCallAsync(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): DurableFuture {
        throw $this->unsupported(__FUNCTION__);
    }

    /** @param array<string, string> $headers */
    public function serviceCallHandle(
        string $service,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): CallHandle {
        throw $this->unsupported(__FUNCTION__);
    }

    /** @param array<string, string> $headers */
    public function objectCallHandle(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): CallHandle {
        throw $this->unsupported(__FUNCTION__);
    }

    /** @param array<string, string> $headers */
    public function workflowCallHandle(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): CallHandle {
        throw $this->unsupported(__FUNCTION__);
    }

    /**
     * @return array{0: int, 1: mixed}
     */
    public function select(DurableFuture ...$futures): array
    {
        throw $this->unsupported(__FUNCTION__);
    }

    /**
     * @param list<DurableFuture> $futures
     *
     * @return list<mixed>
     */
    public function awaitAll(array $futures): array
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function awaitAny(DurableFuture ...$futures): mixed
    {
        throw $this->unsupported(__FUNCTION__);
    }

    /**
     * @param list<DurableFuture> $futures
     *
     * @return list<mixed>
     */
    public function awaitAllSucceeded(array $futures): array
    {
        throw $this->unsupported(__FUNCTION__);
    }

    /** @param array<string, string> $headers */
    public function serviceSend(
        string $service,
        string $handler,
        mixed $input = null,
        float $delaySeconds = 0.0,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): void {
        throw $this->unsupported(__FUNCTION__);
    }

    /** @param array<string, string> $headers */
    public function objectSend(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        float $delaySeconds = 0.0,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): void {
        throw $this->unsupported(__FUNCTION__);
    }

    /** @param array<string, string> $headers */
    public function workflowSend(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        float $delaySeconds = 0.0,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): void {
        throw $this->unsupported(__FUNCTION__);
    }

    public function genericSend(
        string $service,
        string $key,
        string $handler,
        string $parameter,
        ?int $delayMillis = null,
        ?string $idempotencyKey = null,
    ): string {
        throw $this->unsupported(__FUNCTION__);
    }

    public function cancel(string $invocationId): void
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function awakeable(): Awakeable
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function resolveAwakeable(string $id, mixed $value = null): void
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function rejectAwakeable(string $id, string $message): void
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function createSignal(string $name): DurableFuture
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function resolveSignal(string $invocationId, string $name, mixed $value = null): void
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function rejectSignal(string $invocationId, string $name, string $reason): void
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function random(): ContextRand
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function promise(string $name): mixed
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function peekPromise(string $name): mixed
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function resolvePromise(string $name, mixed $value = null): void
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function rejectPromise(string $name, string $reason): void
    {
        throw $this->unsupported(__FUNCTION__);
    }

    private function unsupported(string $method): BadMethodCallException
    {
        return new BadMethodCallException(\sprintf(
            '%s::%s() is not modelled by this saga test double, which only fakes run() and workflow state.',
            self::class,
            $method,
        ));
    }
}
