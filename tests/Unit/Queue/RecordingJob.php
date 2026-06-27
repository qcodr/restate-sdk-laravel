<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A minimal `ShouldQueue` job used as a spy across the queue tests.
 *
 * It is an ordinary Laravel job — it has no idea it runs on Restate — which is the whole
 * point: dispatching it on the `restate` connection must serialise, ship, and re-run it
 * exactly like any other driver. {@see handle()} records its tag in a static sink so a
 * test can prove the job's side effect actually fired when the {@see JobRunner} executed
 * the reconstructed job.
 */
final class RecordingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Tags of every job instance whose {@see handle()} ran, in execution order. Reset in
     * the test case's {@see QueueTestCase::setUp()} so each test starts from a clean sink.
     *
     * @var list<string>
     */
    public static array $handled = [];

    public function __construct(private readonly string $tag)
    {
    }

    public function handle(): void
    {
        self::$handled[] = $this->tag;
    }
}
