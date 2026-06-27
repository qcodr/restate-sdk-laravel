<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Queue;

use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use Qcodr\Restate\Laravel\Client\RestateClient;

/**
 * Registers the `restate` queue driver so `ShouldQueue` jobs can run on Restate.
 *
 * It extends Laravel's {@see QueueManager} with a {@see RestateConnector} bound to the
 * `restate` driver name. A config connection of `['driver' => 'restate', ...]` then
 * resolves to a {@see RestateQueue}, and any job dispatched on it is handed to the Restate
 * ingress instead of a database/Redis backlog.
 *
 * This provider is intentionally self-contained — it depends only on the shared
 * {@see RestateClient} bound by the main `RestateServiceProvider` — so the main provider
 * can register it (`$this->app->register(RestateQueueServiceProvider::class)`) once the
 * queue integration is wired in, keeping the queue concern in its own slice.
 *
 * The connector is added in {@see boot()} against the {@see QueueManager} singleton: by
 * boot time the framework's queue service is bound, and because the manager is a singleton
 * the connector lands on the same instance every connection resolves through — independent
 * of provider ordering. The {@see RestateClient} is resolved lazily inside the connector
 * factory, so nothing is built until a `restate` connection is actually used.
 */
final class RestateQueueServiceProvider extends ServiceProvider
{
    /**
     * The queue driver name a `queue.connections.*` block selects with `'driver' => 'restate'`.
     */
    private const DRIVER = 'restate';

    public function boot(): void
    {
        $manager = $this->app->make('queue');

        if (!$manager instanceof QueueManager) {
            return;
        }

        $manager->addConnector(self::DRIVER, function (): RestateConnector {
            return new RestateConnector($this->app->make(RestateClient::class));
        });
    }
}
