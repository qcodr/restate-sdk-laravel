<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Queue;

use Illuminate\Container\Container;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Jobs\SyncJob;
use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

/**
 * The Restate-side executor for jobs pushed on the `restate` queue connection.
 *
 * {@see RestateQueue} serialises a `ShouldQueue` job with Laravel's normal payload
 * envelope and sends it to this service as a fire-and-forget invocation. Here the
 * envelope is turned back into a live job and run through Laravel's own
 * {@see CallQueuedHandler} — the very class a `queue:work` worker uses — so the job's
 * `handle()` (middleware, dependency injection, batching, chaining) behaves exactly as it
 * would on any other driver, *without* the job needing to know it runs on Restate.
 *
 * Durability comes from running that work inside a single {@see Context::run()} step:
 *
 *  - The closure executes once; its (null) result is journaled. On replay the runtime
 *    returns the stored result instead of re-running `handle()`, giving **exactly-once**
 *    side effects across the invocation's lifecycle.
 *  - A job that throws an ordinary (non-terminal) exception lets that throwable propagate
 *    out of `run()`, so Restate fails the attempt and **retries the whole invocation** —
 *    durable, restart-surviving retries with no `queue:work` loop. A
 *    {@see TerminalException} instead fails the job permanently (no retry), the right
 *    mapping for a non-recoverable job.
 *
 * Note on retry governance: because Restate drives retries, a job's Laravel `tries` /
 * `backoff` / `timeout` settings are **not** consulted — the Restate service's invocation
 * retry policy governs instead. This is the intended trade for durable execution.
 */
#[Service]
final class JobRunner
{
    /**
     * Connection / queue labels stamped on the reconstructed {@see SyncJob}. They feed
     * Laravel's job/queue events for parity; Restate routes by service, not by these.
     */
    private const CONNECTION_NAME = 'restate';

    /**
     * The concrete container is injected (not the contract) because {@see SyncJob} type-hints
     * {@see Container} directly. Handlers receive constructor DI from the Laravel container,
     * which binds itself under this class, so this resolves to the running application.
     */
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Reconstruct and run one queued job, durably.
     *
     * The input is the **decoded** job envelope (the JsonSerde gotcha: the handler argument
     * arrives as an associative array, not a typed object), i.e. exactly what
     * {@see \Illuminate\Queue\Queue::createPayload()} produced — `uuid`, `displayName`,
     * `job`, and a `data` block carrying the serialised command.
     *
     * @param Context                   $ctx     the durable execution context
     * @param array<string, mixed>|null $payload the decoded job envelope
     *
     * @return mixed the journaled step result (null) — irrelevant to the fire-and-forget caller
     */
    #[Handler]
    public function run(Context $ctx, ?array $payload): mixed
    {
        $envelope = $this->requireEnvelope($payload);
        $data = $this->requireCommandData($envelope);

        // Re-encode the envelope so the reconstructed job's getRawBody() matches the
        // original wire form (its payload() decodes back to the same envelope).
        $rawBody = \json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return $ctx->run('handle', function () use ($rawBody, $data): null {
            $job = new SyncJob($this->container, $rawBody, self::CONNECTION_NAME, self::CONNECTION_NAME);

            $this->container->make(CallQueuedHandler::class)->call($job, $data);

            return null;
        });
    }

    /**
     * Validate that a payload arrived at all. A missing/empty body is a caller error that
     * cannot be fixed by retrying, so it fails terminally.
     *
     * @param array<string, mixed>|null $payload
     *
     * @return array<string, mixed>
     */
    private function requireEnvelope(?array $payload): array
    {
        if ($payload === null) {
            throw new TerminalException(
                'RestateQueue JobRunner received an empty payload; nothing to execute.',
                422,
            );
        }

        return $payload;
    }

    /**
     * Extract the serialised command `data` block, failing terminally if the envelope is
     * malformed — a corrupt payload will never succeed on retry.
     *
     * @param array<string, mixed> $envelope
     *
     * @return array<mixed, mixed> the `data` block handed verbatim to {@see CallQueuedHandler::call()}
     */
    private function requireCommandData(array $envelope): array
    {
        $data = $envelope['data'] ?? null;

        if (!\is_array($data)) {
            throw new TerminalException(
                'RestateQueue JobRunner payload is missing its serialised command data.',
                422,
            );
        }

        return $data;
    }
}
