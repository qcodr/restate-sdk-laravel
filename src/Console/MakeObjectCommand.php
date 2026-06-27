<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Scaffolds a Restate Virtual Object (`#[VirtualObject]`) — per-key state with a single
 * writer — into the app's `App\Restate` namespace (app/Restate), the same directory the
 * discovery scanner watches, so a new object is one command away from being bound.
 */
#[AsCommand(name: 'make:restate-object')]
final class MakeObjectCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:restate-object';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Restate virtual object class';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Restate virtual object';

    /**
     * @return string the absolute path to the virtual-object stub
     */
    protected function getStub()
    {
        return __DIR__ . '/stubs/object.stub';
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
