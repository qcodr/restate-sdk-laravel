<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Queue;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Qcodr\Restate\Laravel\Queue\JobRunner;
use Qcodr\Restate\Laravel\Queue\RestateQueueServiceProvider;
use Qcodr\Restate\Laravel\RestateServiceProvider;

/**
 * Testbench base for the Restate queue slice.
 *
 * Registers BOTH the main {@see RestateServiceProvider} (which binds the
 * {@see \Qcodr\Restate\Laravel\Client\RestateClient} the connector needs) and the
 * {@see RestateQueueServiceProvider} (which adds the `restate` driver), then wires a
 * `restate` queue connection so jobs can be dispatched on it. The shared package
 * `TestCase` only registers the main provider, so the queue slice carries its own base to
 * stay self-contained.
 */
abstract class QueueTestCase extends Orchestra
{
    /**
     * @param Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RestateServiceProvider::class, RestateQueueServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);

        // Expose the JobRunner as a Restate service (parity with a real deployment) and
        // point a `restate` queue connection at it.
        $config->set('restate.services', [JobRunner::class]);
        $config->set('queue.connections.restate', [
            'driver' => 'restate',
            'service' => 'JobRunner',
            'handler' => 'run',
            'queue' => 'default',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        RecordingJob::$handled = [];
    }
}
