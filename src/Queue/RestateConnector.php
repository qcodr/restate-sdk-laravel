<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Queue;

use Illuminate\Queue\Connectors\ConnectorInterface;
use Qcodr\Restate\Laravel\Client\RestateClient;

/**
 * Builds a {@see RestateQueue} for the `restate` queue driver.
 *
 * Laravel's {@see \Illuminate\Queue\QueueManager} calls {@see connect()} with the matching
 * `queue.connections.*` config block when a connection on this driver is first resolved.
 * The connector holds the single shared {@see RestateClient} (the ingress dispatcher,
 * resolved from the container by the service provider) and reads the Restate target —
 * which service/handler queued jobs land on — from that config, so a deployment can point
 * the driver at a differently named {@see JobRunner} service without code changes.
 */
final class RestateConnector implements ConnectorInterface
{
    /**
     * Default Restate service name jobs are dispatched to. Matches the {@see JobRunner}
     * class short name, which is how the SDK derives an un-named `#[Service]`'s name.
     */
    private const DEFAULT_SERVICE = 'JobRunner';

    /**
     * Default handler method on that service. Matches {@see JobRunner::run()}, which is how
     * the SDK derives an un-named `#[Handler]`'s name (the method name).
     */
    private const DEFAULT_HANDLER = 'run';

    public function __construct(private readonly RestateClient $client)
    {
    }

    /**
     * Establish a queue connection for the `restate` driver.
     *
     * Recognised config keys (all optional):
     *  - `service`      Restate service name jobs land on (default `JobRunner`)
     *  - `handler`      handler method on that service (default `run`)
     *  - `queue`        cosmetic Laravel queue label for un-named dispatches (default `default`)
     *  - `after_commit` defer dispatch until the open DB transaction commits (default false)
     *
     * @param array<string, mixed> $config the `queue.connections.<name>` block
     */
    public function connect(array $config): RestateQueue
    {
        return new RestateQueue(
            $this->client,
            $this->stringConfig($config, 'service', self::DEFAULT_SERVICE),
            $this->stringConfig($config, 'handler', self::DEFAULT_HANDLER),
            $this->stringConfig($config, 'queue', 'default'),
            (bool) ($config['after_commit'] ?? false),
        );
    }

    /**
     * Read a non-empty string config value, falling back to a default for missing,
     * empty, or non-string entries — never trusting the shape of external config.
     *
     * @param array<string, mixed> $config
     */
    private function stringConfig(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}
