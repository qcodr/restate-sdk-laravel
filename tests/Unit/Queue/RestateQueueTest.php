<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Queue;

use DateInterval;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Qcodr\Restate\Laravel\Queue\JobRunner;
use Qcodr\Restate\Laravel\Queue\RestateQueue;

/**
 * Drives the `restate` queue connection end to end against a faked ingress
 * (`Http::fake()`): dispatching a `ShouldQueue` job must serialise it and POST the
 * envelope to the {@see JobRunner} send path, delays must ride along as the ingress
 * `delay` parameter, and the exact bytes shipped must be replayable through the
 * {@see JobRunner} — proving the push/execute round trip without a Restate runtime.
 */
final class RestateQueueTest extends QueueTestCase
{
    private const SEND_URL = 'http://localhost:8080/JobRunner/run/send';

    public function testDispatchingAJobPostsTheSerialisedPayloadToTheJobRunnerSendPath(): void
    {
        Http::fake(['*' => Http::response(['invocationId' => 'inv_1', 'status' => 'Accepted'], 200)]);

        $this->dispatcher()->dispatch(
            (new RecordingJob('payload-1'))->onConnection('restate'),
        );

        Http::assertSent(function (Request $request): bool {
            $body = \json_decode($request->body(), true);

            return $request->method() === 'POST'
                && $request->url() === self::SEND_URL
                && \is_array($body)
                && \is_array($body['data'] ?? null)
                && ($body['data']['commandName'] ?? null) === RecordingJob::class
                && $request->hasHeader('Idempotency-Key');
        });
    }

    public function testTheIdempotencyKeyIsThePayloadUuid(): void
    {
        Http::fake(['*' => Http::response(['invocationId' => 'inv_idem'], 200)]);

        $this->queue()->push(new RecordingJob('idem'));

        Http::assertSent(function (Request $request): bool {
            $body = \json_decode($request->body(), true);
            $uuid = \is_array($body) ? ($body['uuid'] ?? null) : null;

            return \is_string($uuid)
                && $uuid !== ''
                && $request->hasHeader('Idempotency-Key', $uuid);
        });
    }

    public function testLaterAddsTheDelayQueryParameterInMilliseconds(): void
    {
        Http::fake(['*' => Http::response(['invocationId' => 'inv_2'], 200)]);

        $this->dispatcher()->dispatch(
            (new RecordingJob('later-1'))->onConnection('restate')->delay(60),
        );

        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === self::SEND_URL . '?delay=60000ms';
        });
    }

    public function testPushedPayloadExecutesThroughTheJobRunner(): void
    {
        Http::fake(['*' => Http::response(['invocationId' => 'inv_rt'], 200)]);

        // Push a job, capture the exact envelope the ingress received, then replay it
        // through the JobRunner — the same hop a real Restate invocation makes.
        $this->queue()->push(new RecordingJob('round-trip'));

        $sent = Http::recorded()->first();
        self::assertIsArray($sent);
        $request = $sent[0];
        self::assertInstanceOf(Request::class, $request);

        $decoded = \json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        // The wire payload is a JSON object, so every key is a string; rebuild it as a
        // string-keyed envelope (the shape the handler declares) without trusting the type.
        $envelope = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key)) {
                $envelope[$key] = $value;
            }
        }

        $context = new FakeContext();
        (new JobRunner(app()))->run($context, $envelope);

        self::assertSame(['round-trip'], RecordingJob::$handled);
        self::assertSame(['handle'], $context->ranSteps());
    }

    public function testPushRawDispatchesAPreSerialisedEnvelopeWithItsUuidAsTheIdempotencyKey(): void
    {
        Http::fake(['*' => Http::response(['invocationId' => 'inv_raw'], 200)]);

        $invocationId = $this->queue()->pushRaw('{"uuid":"u-raw","data":{"commandName":"X"}}');

        self::assertSame('inv_raw', $invocationId);
        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === self::SEND_URL
                && $request->hasHeader('Idempotency-Key', 'u-raw');
        });
    }

    public function testPushRawHonoursADelayOptionInMilliseconds(): void
    {
        Http::fake(['*' => Http::response(['invocationId' => 'inv_raw_delay'], 200)]);

        $this->queue()->pushRaw('{"uuid":"u-delay","data":[]}', null, ['delay' => 60]);

        Http::assertSent(static fn (Request $request): bool => $request->url() === self::SEND_URL . '?delay=60000ms');
    }

    public function testPushRawRejectsAnUnsupportedDelayType(): void
    {
        Http::fake(['*' => Http::response(['invocationId' => 'inv_x'], 200)]);

        try {
            $this->queue()->pushRaw('{"uuid":"u","data":[]}', null, ['delay' => 'soon']);
            self::fail('Expected an InvalidArgumentException for a string delay.');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'RestateQueue delay must be an int, DateInterval, or DateTimeInterface; got string.',
                $e->getMessage(),
            );
        }
    }

    public function testDecodingANonObjectPayloadThrows(): void
    {
        Http::fake(['*' => Http::response(['invocationId' => 'inv_x'], 200)]);

        try {
            $this->queue()->pushRaw('123');
            self::fail('Expected an InvalidArgumentException for a non-object JSON payload.');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'RestateQueue expected a JSON object job payload; got int.',
                $e->getMessage(),
            );
        }
    }

    public function testLaterAcceptsADateTimeInterfaceDelay(): void
    {
        Http::fake(['*' => Http::response(['invocationId' => 'inv_dt'], 200)]);

        $this->queue()->later(Carbon::now()->addSeconds(45), new RecordingJob('dt'));

        Http::assertSent(static function (Request $request): bool {
            // A DateTimeInterface delay must resolve to a positive millisecond delay query.
            return \str_contains($request->url(), self::SEND_URL . '?delay=')
                && \str_ends_with($request->url(), 'ms');
        });
    }

    public function testLaterAcceptsADateIntervalDelay(): void
    {
        Http::fake(['*' => Http::response(['invocationId' => 'inv_di'], 200)]);

        $this->queue()->later(new DateInterval('PT30S'), new RecordingJob('di'));

        Http::assertSent(static function (Request $request): bool {
            return \str_contains($request->url(), self::SEND_URL . '?delay=')
                && \str_ends_with($request->url(), 'ms');
        });
    }

    public function testReadSideReflectsThatRestateOwnsExecution(): void
    {
        // No worker reserves from this connection; the runtime drives execution, so the
        // pollable surface is empty by design (no `queue:work` against `restate`).
        $queue = $this->queue();

        self::assertSame(0, $queue->size());
        self::assertSame(0, $queue->pendingSize());
        self::assertSame(0, $queue->delayedSize());
        self::assertSame(0, $queue->reservedSize());
        self::assertNull($queue->creationTimeOfOldestPendingJob());
        self::assertNull($queue->pop());
    }

    private function dispatcher(): Dispatcher
    {
        return app(Dispatcher::class);
    }

    private function queue(): RestateQueue
    {
        $manager = app('queue');
        self::assertInstanceOf(QueueManager::class, $manager);

        $queue = $manager->connection('restate');
        self::assertInstanceOf(RestateQueue::class, $queue);

        return $queue;
    }
}
