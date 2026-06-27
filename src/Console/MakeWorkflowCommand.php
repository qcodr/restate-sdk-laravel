<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Scaffolds a Restate Workflow (`#[Workflow]`) — a virtual object whose `run` handler runs
 * exactly once per key — into the app's `App\Restate` namespace (app/Restate), the same
 * directory the discovery scanner watches, so a new workflow is one command away from being
 * bound.
 */
#[AsCommand(name: 'make:restate-workflow')]
final class MakeWorkflowCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:restate-workflow';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Restate workflow class';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Restate workflow';

    /**
     * @return string the absolute path to the workflow stub
     */
    protected function getStub()
    {
        return __DIR__ . '/stubs/workflow.stub';
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
