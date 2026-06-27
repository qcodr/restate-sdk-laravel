<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Scaffolds a Restate Service (`#[Service]`) — a set of stateless handlers with unlimited
 * concurrency — into the app's `App\Restate` namespace (app/Restate), the same directory
 * the discovery scanner watches, so a new service is one command away from being bound.
 */
#[AsCommand(name: 'make:restate-service')]
final class MakeServiceCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:restate-service';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Restate service class';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Restate service';

    /**
     * @return string the absolute path to the service stub
     */
    protected function getStub()
    {
        return __DIR__ . '/stubs/service.stub';
    }

    /**
     * Place generated classes under the app's `Restate` sub-namespace (app/Restate), the
     * directory the {@see \Qcodr\Restate\Laravel\Discovery\ServiceScanner} discovers.
     *
     * @param string $rootNamespace the application root namespace (e.g. `App\`)
     *
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace . '\Restate';
    }
}
