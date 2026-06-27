<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Console;

use Illuminate\Console\Command;
use Qcodr\Restate\Laravel\Logging\RestateLogger;
use Qcodr\Restate\Laravel\RestateManager;
use Qcodr\Restate\Sdk\Server\AmpStreamingServer;

/**
 * Serves the configured endpoint over true bidirectional HTTP/2 (amphp) — an alternative
 * to the in-app request/response route, enabling cancellation, signals, and fewer
 * re-invokes for suspension-heavy handlers. Blocks until SIGINT/SIGTERM.
 *
 * Requires amphp/http-server (`composer require amphp/http-server`); `--workers` > 1
 * pre-forks worker processes (needs ext-pcntl).
 */
final class ServeCommand extends Command
{
    /** @var string */
    protected $signature = 'restate:serve
        {--host= : Bind host (default from config restate.server.host)}
        {--port= : Bind port (default from config restate.server.port)}
        {--workers= : Worker processes; 0 = one per CPU (default from config)}';

    /** @var string */
    protected $description = 'Serve the Restate endpoint over bidirectional HTTP/2 (amphp)';

    public function handle(RestateManager $manager): int
    {
        if (!\class_exists(AmpStreamingServer::class)) {
            $this->error('AmpStreamingServer requires amphp/http-server; run: composer require amphp/http-server');

            return self::FAILURE;
        }

        if ($manager->serviceClasses() === []) {
            $this->warn('No services configured. Add classes to config/restate.php (services).');

            return self::FAILURE;
        }

        $server = $manager->serverConfig();
        $host = $this->stringOption('host') ?? $server['host'];
        $port = $this->intOption('port') ?? $server['port'];
        $workers = $this->intOption('workers') ?? $server['workers'];

        $this->info("Serving Restate endpoint (amphp bidi) on http://{$host}:{$port}");

        (new AmpStreamingServer($manager->endpoint(), logger: app(RestateLogger::class)))
            ->listen($host, $port, $workers);

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return \is_string($value) && \is_numeric($value) ? (int) $value : null;
    }
}
