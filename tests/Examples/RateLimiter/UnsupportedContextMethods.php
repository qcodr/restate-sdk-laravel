<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Examples\RateLimiter;

use LogicException;
use Psr\Log\LoggerInterface;
use Qcodr\Restate\Sdk\Context\Awakeable;
use Qcodr\Restate\Sdk\Context\CallHandle;
use Qcodr\Restate\Sdk\Context\ContextRand;
use Qcodr\Restate\Sdk\Context\DurableFuture;
use Qcodr\Restate\Sdk\Context\RunOptions;
use Qcodr\Restate\Sdk\Context\TraceContext;

/**
 * The durable-execution surface of {@see \Qcodr\Restate\Sdk\Context\Context} that the
 * rate-limiter example does not exercise (runs, timers, calls, sends, awakeables,
 * signals, randomness, logging).
 *
 * It is factored into a trait purely to keep {@see FakeObjectContext} focused on the part
 * that matters — object state (get/set/clear/key). Every method here throws, so a test
 * that accidentally relied on durable execution would fail loudly with a precise message
 * rather than silently no-op. The rate-limiter handlers only ever touch state, so this is
 * never reached in the green path.
 */
trait UnsupportedContextMethods
{
    public function invocationId(): string
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @return array<string, string>
     */
    public function requestHeaders(): array
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function requestIdempotencyKey(): ?string
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function traceContext(): ?TraceContext
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function run(string $name, callable $action, ?RunOptions $options = null): mixed
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function runAsync(string $name, callable $action): DurableFuture
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function sleep(float $seconds): void
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function timer(float $seconds): DurableFuture
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @param array<string, string> $headers
     */
    public function serviceCall(
        string $service,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): mixed {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @param array<string, string> $headers
     */
    public function objectCall(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): mixed {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @param array<string, string> $headers
     */
    public function workflowCall(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): mixed {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function genericCall(
        string $service,
        string $key,
        string $handler,
        string $parameter,
        ?string $idempotencyKey = null,
    ): string {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @param array<string, string> $headers
     */
    public function serviceCallAsync(
        string $service,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): DurableFuture {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @param array<string, string> $headers
     */
    public function objectCallAsync(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): DurableFuture {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @param array<string, string> $headers
     */
    public function workflowCallAsync(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): DurableFuture {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @param array<string, string> $headers
     */
    public function serviceCallHandle(
        string $service,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): CallHandle {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @param array<string, string> $headers
     */
    public function objectCallHandle(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): CallHandle {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @param array<string, string> $headers
     */
    public function workflowCallHandle(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): CallHandle {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @return array{0: int, 1: mixed}
     */
    public function select(DurableFuture ...$futures): array
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @param list<DurableFuture> $futures
     *
     * @return list<mixed>
     */
    public function awaitAll(array $futures): array
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function awaitAny(DurableFuture ...$futures): mixed
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @param list<DurableFuture> $futures
     *
     * @return list<mixed>
     */
    public function awaitAllSucceeded(array $futures): array
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @param array<string, string> $headers
     */
    public function serviceSend(
        string $service,
        string $handler,
        mixed $input = null,
        float $delaySeconds = 0.0,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): void {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @param array<string, string> $headers
     */
    public function objectSend(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        float $delaySeconds = 0.0,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): void {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    /**
     * @param array<string, string> $headers
     */
    public function workflowSend(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        float $delaySeconds = 0.0,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): void {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function genericSend(
        string $service,
        string $key,
        string $handler,
        string $parameter,
        ?int $delayMillis = null,
        ?string $idempotencyKey = null,
    ): string {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function cancel(string $invocationId): void
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function awakeable(): Awakeable
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function resolveAwakeable(string $id, mixed $value = null): void
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function rejectAwakeable(string $id, string $message): void
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function createSignal(string $name): DurableFuture
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function resolveSignal(string $invocationId, string $name, mixed $value = null): void
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function rejectSignal(string $invocationId, string $name, string $reason): void
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function random(): ContextRand
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    public function logger(): LoggerInterface
    {
        throw new LogicException($this->unsupported(__FUNCTION__));
    }

    private function unsupported(string $method): string
    {
        return \sprintf(
            '%s() is not implemented by the rate-limiter fake context. The example only '
            . 'exercises object state (get/set/clear/key); durable-execution primitives are out of scope.',
            $method,
        );
    }
}
