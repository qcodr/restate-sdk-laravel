<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Queue;

use BadMethodCallException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qcodr\Restate\Sdk\Context\Awakeable;
use Qcodr\Restate\Sdk\Context\CallHandle;
use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Context\ContextRand;
use Qcodr\Restate\Sdk\Context\DurableFuture;
use Qcodr\Restate\Sdk\Context\RunOptions;
use Qcodr\Restate\Sdk\Context\TraceContext;

/**
 * An in-memory test double for the base {@see Context}.
 *
 * `Context` is a plain PHP interface, so the {@see JobRunner} can be driven in a unit test
 * with no Restate runtime, journal, or network. This double models only the one feature
 * the JobRunner relies on:
 *
 *   - {@see run()}: executes the closure immediately and, on success, records the step
 *     name. Recording *after* the closure returns mirrors a real journal entry (written
 *     once the step succeeds), so {@see ranSteps()} reads as an honest trace of what
 *     committed — letting a test assert the job ran inside a durable step.
 *
 * Every other context method is a distributed primitive that cannot be faithfully faked
 * in-process, so each throws {@see BadMethodCallException}: if the handler under test ever
 * reaches for one, the test fails loudly instead of silently passing. The JobRunner never
 * does, which is what makes this minimal double sufficient.
 */
final class FakeContext implements Context
{
    /** @var list<string> names of the durable steps that ran to completion, in order */
    private array $ranSteps = [];

    /**
     * The durable steps that completed successfully, in execution order.
     *
     * @return list<string>
     */
    public function ranSteps(): array
    {
        return $this->ranSteps;
    }

    public function run(string $name, callable $action, ?RunOptions $options = null): mixed
    {
        $result = $action();
        $this->ranSteps[] = $name;

        return $result;
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

    private function unsupported(string $method): BadMethodCallException
    {
        return new BadMethodCallException(\sprintf(
            '%s::%s() is not modelled by this queue test double, which only fakes run().',
            self::class,
            $method,
        ));
    }
}
