<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Queue;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use InvalidArgumentException;
use Qcodr\Restate\Laravel\Client\RestateClient;

/**
 * A Laravel queue *connection* whose executor is Restate, not a worker pool.
 *
 * A job dispatched on this connection is serialised with Laravel's own
 * {@see Queue::createPayload()} (so it carries the same `command`/`data`/`uuid`
 * envelope every other driver uses) and then handed to the Restate **ingress** as a
 * fire-and-forget invocation of a single Restate service handler — the {@see JobRunner}.
 * The runtime, not `queue:work`, drives execution: it persists the invocation, runs the
 * handler exactly once, and applies durable retries on transient failure.
 *
 * Because Restate *pushes* work into a handler rather than holding it in a list a worker
 * polls, the read-side of the {@see QueueContract} has no meaning here:
 *
 *  - {@see pop()} returns `null` — there is nothing for a worker to reserve, and you do
 *    not run `queue:work` against this connection; Restate delivers the job to the
 *    handler itself.
 *  - {@see size()} and the `*Size()` counters return `0`, and
 *    {@see creationTimeOfOldestPendingJob()} returns `null` — the backlog lives in the
 *    Restate runtime's own storage (and is observable through Restate's CLI/UI), not in
 *    a store this connection can introspect.
 *
 * The write-side ({@see push()}, {@see pushRaw()}, {@see later()}) is the whole point:
 * it converts a queued job into a durable Restate invocation. The job payload's `uuid`
 * is sent as the ingress idempotency key, so a duplicated push (a retried dispatch, an
 * at-least-once event) de-duplicates to a single invocation.
 */
final class RestateQueue extends Queue implements QueueContract
{
    /**
     * Default Laravel queue label applied when a dispatch does not name one. It is
     * cosmetic for this driver — it rides along in the serialised payload for parity with
     * other connections, but Restate routes by service/handler, not by queue name.
     */
    private const DEFAULT_QUEUE = 'default';

    /**
     * Milliseconds per second — the unit conversion between Laravel's second-granular
     * delays ({@see Queue::secondsUntil()}) and the {@see RestateClient::send()} API,
     * which schedules in milliseconds.
     */
    private const MILLIS_PER_SECOND = 1000;

    private readonly RestateClient $client;

    private readonly string $service;

    private readonly string $handler;

    private readonly string $defaultQueue;

    /**
     * @param RestateClient $client             the ingress dispatcher that POSTs the invocation
     * @param string        $service            the Restate service name the job lands on (the
     *                                          {@see JobRunner} service, e.g. `JobRunner`)
     * @param string        $handler            the handler method on that service (e.g. `run`)
     * @param string        $defaultQueue       queue label for un-named dispatches
     * @param bool          $dispatchAfterCommit dispatch only after the open DB transaction commits
     */
    public function __construct(
        RestateClient $client,
        string $service,
        string $handler,
        string $defaultQueue = self::DEFAULT_QUEUE,
        bool $dispatchAfterCommit = false,
    ) {
        $this->client = $client;
        $this->service = $service;
        $this->handler = $handler;
        $this->defaultQueue = $defaultQueue !== '' ? $defaultQueue : self::DEFAULT_QUEUE;
        $this->dispatchAfterCommit = $dispatchAfterCommit;
    }

    /**
     * The number of jobs waiting on this connection.
     *
     * Always `0`: Restate owns the backlog. There is no list this connection can count —
     * pending invocations live in the runtime's storage and are observed through Restate
     * itself, not through Laravel's queue API.
     *
     * @param string|null $queue
     */
    public function size($queue = null): int
    {
        return 0;
    }

    /**
     * Pending (ready, not yet running) job count. Always `0` — see {@see size()}.
     *
     * @param string|null $queue
     */
    public function pendingSize($queue = null): int
    {
        return 0;
    }

    /**
     * Delayed (scheduled-for-later) job count. Always `0` — delayed invocations are
     * durable timers held by the Restate runtime, not rows this connection can count.
     *
     * @param string|null $queue
     */
    public function delayedSize($queue = null): int
    {
        return 0;
    }

    /**
     * Reserved (in-flight) job count. Always `0` — in-flight work is a running Restate
     * invocation, tracked by the runtime rather than reserved from this connection.
     *
     * @param string|null $queue
     */
    public function reservedSize($queue = null): int
    {
        return 0;
    }

    /**
     * Creation timestamp of the oldest pending job. Always `null`: there is no pending
     * list here to inspect (see {@see size()}).
     *
     * @param string|null $queue
     */
    public function creationTimeOfOldestPendingJob($queue = null): ?int
    {
        return null;
    }

    /**
     * Serialise a job and dispatch it to Restate immediately.
     *
     * @param Closure|string|object $job
     * @param mixed                  $data
     * @param string|null            $queue
     *
     * @return mixed the Restate invocation id (the "job id" for this connection)
     */
    public function push($job, $data = '', $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue ?? $this->defaultQueue, $data),
            $queue,
            null,
            fn (string $payload): string => $this->dispatchToRestate($payload, null),
        );
    }

    /**
     * Dispatch an already-serialised payload to Restate.
     *
     * Honours an `options['delay']` (seconds, or a date/interval Laravel can resolve) so
     * the same code path serves both immediate and delayed raw pushes.
     *
     * @param string                    $payload the JSON job envelope from {@see createPayload()}
     * @param string|null               $queue
     * @param array<string, mixed>      $options
     *
     * @return mixed the Restate invocation id
     */
    public function pushRaw($payload, $queue = null, array $options = []): mixed
    {
        return $this->dispatchToRestate($payload, $this->delaySeconds($options['delay'] ?? null));
    }

    /**
     * Serialise a job and dispatch it to Restate to run after a delay.
     *
     * The delay becomes a durable, restart-surviving timer on the Restate side (via the
     * ingress `delay` parameter), so the schedule survives process restarts without a
     * worker holding it.
     *
     * @param DateTimeInterface|DateInterval|int $delay
     * @param Closure|string|object               $job
     * @param mixed                                $data
     * @param string|null                          $queue
     *
     * @return mixed the Restate invocation id
     */
    public function later($delay, $job, $data = '', $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue ?? $this->defaultQueue, $data, $delay),
            $queue,
            $delay,
            fn (string $payload, mixed $queue, mixed $resolvedDelay): string => $this->dispatchToRestate(
                $payload,
                $this->delaySeconds($resolvedDelay),
            ),
        );
    }

    /**
     * No-op: Restate delivers jobs to the handler; nothing is reserved from a list here.
     *
     * Returning `null` means a `queue:work` loop pointed at this connection would simply
     * idle — which is correct, because you should not run one. The Restate runtime is the
     * executor; this method exists only to satisfy the {@see QueueContract}.
     *
     * @param string|null $queue
     */
    public function pop($queue = null): ?Job
    {
        return null;
    }

    /**
     * Send one serialised job envelope to the Restate ingress as a fire-and-forget
     * invocation of the configured service/handler.
     *
     * The envelope is decoded back to an array before being handed to
     * {@see RestateClient::send()} (which JSON-encodes the single handler argument): that
     * way the {@see JobRunner} handler receives the same structured payload it was built
     * from, rather than a double-encoded JSON string. The payload's `uuid` is forwarded as
     * the idempotency key so a duplicated dispatch resolves to a single invocation.
     *
     * @param string   $payload the JSON job envelope from {@see createPayload()}
     * @param int|null $delaySeconds delay in whole seconds, or null for immediate
     *
     * @return string the Restate invocation id the ingress assigns
     */
    private function dispatchToRestate(string $payload, ?int $delaySeconds): string
    {
        $decoded = $this->decodePayload($payload);

        return $this->client->send(
            $this->service,
            $this->handler,
            $decoded,
            null,
            $this->idempotencyKeyFrom($decoded),
            $delaySeconds === null ? null : $delaySeconds * self::MILLIS_PER_SECOND,
        );
    }

    /**
     * Resolve a delay argument to whole seconds, or null for an immediate dispatch.
     *
     * Laravel hands delays as an int (seconds), a {@see DateInterval}, or a
     * {@see DateTimeInterface} (see {@see Queue::secondsUntil()}); anything else is a
     * boundary error and fails fast rather than being silently coerced to zero.
     */
    private function delaySeconds(mixed $delay): ?int
    {
        if ($delay === null) {
            return null;
        }

        if (\is_int($delay) || $delay instanceof DateTimeInterface || $delay instanceof DateInterval) {
            return $this->secondsUntil($delay);
        }

        throw new InvalidArgumentException(
            'RestateQueue delay must be an int, DateInterval, or DateTimeInterface; got '
            . \get_debug_type($delay) . '.',
        );
    }

    /**
     * Decode the serialised payload to the structured array the handler will receive.
     *
     * The payload is produced by {@see createPayload()} and is therefore always a JSON
     * object; we validate that at the boundary rather than trusting it, and fail loudly
     * if some upstream produced a non-object payload.
     *
     * @return array<mixed, mixed>
     */
    private function decodePayload(string $payload): array
    {
        $decoded = \json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new InvalidArgumentException(
                'RestateQueue expected a JSON object job payload; got ' . \get_debug_type($decoded) . '.',
            );
        }

        return $decoded;
    }

    /**
     * The job `uuid` to use as the ingress idempotency key, or null when absent.
     *
     * @param array<mixed, mixed> $payload
     */
    private function idempotencyKeyFrom(array $payload): ?string
    {
        $uuid = $payload['uuid'] ?? null;

        return \is_string($uuid) && $uuid !== '' ? $uuid : null;
    }
}
