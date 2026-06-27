<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Codegen;

use Illuminate\Support\ServiceProvider;
use Qcodr\Restate\Laravel\Console\CodegenCommand;

/**
 * Registers the `restate:codegen` client generator with Artisan.
 *
 * Kept separate from the main RestateServiceProvider so the codegen surface is
 * self-contained — the parent registers this provider alongside the main one. The command is
 * bound only when running in the console, since generating client stubs has no role in the
 * HTTP request/response or `restate:serve` runtimes.
 */
final class CodegenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CodegenCommand::class]);
        }
    }
}
