<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Queue;

use Qcodr\Restate\Laravel\Queue\JobRunner;
use Qcodr\Restate\Sdk\Error\TerminalException;

/**
 * Unit-tests the Restate-side executor in isolation: given a serialised job envelope and a
 * minimal {@see FakeContext}, {@see JobRunner::run()} must rebuild the Laravel job and run
 * its `handle()` inside a durable step — with no Restate runtime, ingress, or network.
 */
final class JobRunnerTest extends QueueTestCase
{
    public function testRunReconstructsAndExecutesTheJobInsideADurableStep(): void
    {
        $context = new FakeContext();

        (new JobRunner(app()))->run($context, $this->envelopeFor(new RecordingJob('unit')));

        // The job's own side effect fired (it was rebuilt and `handle()` ran)...
        self::assertSame(['unit'], RecordingJob::$handled);
        // ...and it ran inside exactly one durable `run('handle', …)` step.
        self::assertSame(['handle'], $context->ranSteps());
    }

    public function testRunReturnsTheJournaledStepResult(): void
    {
        $context = new FakeContext();

        $result = (new JobRunner(app()))->run($context, $this->envelopeFor(new RecordingJob('unit')));

        self::assertNull($result);
    }

    public function testRunFailsTerminallyOnAnEmptyPayload(): void
    {
        $this->expectException(TerminalException::class);

        (new JobRunner(app()))->run(new FakeContext(), null);
    }

    public function testRunFailsTerminallyWhenTheCommandDataIsMissing(): void
    {
        $context = new FakeContext();

        try {
            (new JobRunner(app()))->run($context, ['uuid' => 'u-1', 'job' => 'Whatever@call']);
            self::fail('Expected a TerminalException for a payload with no command data.');
        } catch (TerminalException $e) {
            self::assertSame(422, $e->statusCode());
            // A malformed payload must never reach the durable step.
            self::assertSame([], $context->ranSteps());
        }
    }

    /**
     * The exact envelope Laravel's queue produces for an object job — `uuid`, the
     * `CallQueuedHandler@call` job class, and a `data` block carrying the serialised
     * command. {@see JobRunner} receives this as the decoded array (the JsonSerde gotcha).
     *
     * @return array<string, mixed>
     */
    private function envelopeFor(RecordingJob $job): array
    {
        return [
            'uuid' => 'uuid-unit-1',
            'displayName' => RecordingJob::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => null,
            'data' => [
                'commandName' => RecordingJob::class,
                'command' => \serialize($job),
            ],
        ];
    }
}
