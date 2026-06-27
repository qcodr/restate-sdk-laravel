<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Discovery;

use Illuminate\Support\ServiceProvider;
use Qcodr\Restate\Laravel\Console\MakeObjectCommand;
use Qcodr\Restate\Laravel\Console\MakeServiceCommand;
use Qcodr\Restate\Laravel\Console\MakeWorkflowCommand;

/**
 * Registers the Restate `make:restate-*` generators with Artisan.
 *
 * Kept separate from the main RestateServiceProvider so the generator surface is
 * self-contained — the parent registers this provider alongside the main one. Commands
 * are bound only when running in the console, since the generators have no role in the
 * HTTP or `restate:serve` runtimes.
 */
final class RestateMakeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeServiceCommand::class,
                MakeObjectCommand::class,
                MakeWorkflowCommand::class,
            ]);
        }
    }
}
