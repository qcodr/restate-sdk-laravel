<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
 * Standard Laravel 12 bootstrap. The Restate package's service provider is auto-discovered
 * from Composer (extra.laravel.providers), so it does not need to be listed in
 * bootstrap/providers.php. No web routes are needed: this app is served to the Restate
 * runtime over `php artisan restate:serve`, not the in-app HTTP route.
 */

return Application::configure(basePath: \dirname(__DIR__))
    ->withRouting(
        commands: __DIR__ . '/../routes/console.php',
    )
    ->withMiddleware(static function (Middleware $middleware): void {
        // No custom middleware: the Restate endpoint is served outside the HTTP kernel.
    })
    ->withExceptions(static function (Exceptions $exceptions): void {
        // Default exception handling is sufficient for the harness.
    })
    ->create();
