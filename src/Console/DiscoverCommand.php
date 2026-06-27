<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Console;

use Illuminate\Console\Command;
use Qcodr\Restate\Laravel\RestateManager;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;

/**
 * Lists the services bound to the endpoint, or (with --json) prints the raw discovery
 * manifest the Restate runtime fetches — useful to verify config without a live runtime.
 */
final class DiscoverCommand extends Command
{
    private const MANIFEST_ACCEPT = 'application/vnd.restate.endpointmanifest.v1+json';

    /** @var string */
    protected $signature = 'restate:discover {--json : Print the raw discovery manifest}';

    /** @var string */
    protected $description = 'List the bound Restate services, or print the discovery manifest';

    public function handle(RestateManager $manager): int
    {
        $classes = $manager->serviceClasses();
        if ($classes === []) {
            $this->warn('No services configured. Add classes to config/restate.php (services).');

            return self::SUCCESS;
        }

        if ($this->option('json') === true) {
            $response = $manager->processor()->process(new HttpRequest(
                'GET',
                '/discover',
                ['accept' => self::MANIFEST_ACCEPT],
                '',
            ));

            $this->line($response->body);

            return $response->status === 200 ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Restate services bound to the endpoint:');
        foreach ($classes as $class) {
            $this->line('  • ' . $class);
        }
        $this->info(\count($classes) . ' service(s).');

        return self::SUCCESS;
    }
}
