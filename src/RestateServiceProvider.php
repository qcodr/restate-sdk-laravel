<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Qcodr\Restate\Laravel\Client\RestateClient;
use Qcodr\Restate\Laravel\Codegen\CodegenServiceProvider;
use Qcodr\Restate\Laravel\Console\DiscoverCommand;
use Qcodr\Restate\Laravel\Console\ServeCommand;
use Qcodr\Restate\Laravel\Discovery\RestateMakeServiceProvider;
use Qcodr\Restate\Laravel\Http\EndpointController;
use Qcodr\Restate\Laravel\Logging\RestateObservabilityServiceProvider;
use Qcodr\Restate\Laravel\Queue\RestateQueueServiceProvider;
use Qcodr\Restate\Laravel\Scheduling\RestateSchedulerServiceProvider;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;

/**
 * Wires the Restate SDK into Laravel:
 *
 *  - merges/publishes the `restate` config;
 *  - binds {@see RestateManager} (builds the endpoint from config, container-resolving
 *    each service) and a {@see RequestProcessor} singleton over it;
 *  - binds the {@see RestateClient} singleton (the ingress dispatcher) from the `ingress`
 *    config, so Laravel code can start invocations via `Restate::client()` or DI;
 *  - registers a catch-all HTTP route at the configured prefix served by
 *    {@see EndpointController} (request/response), unless the prefix is disabled;
 *  - registers the `restate:serve` and `restate:discover` Artisan commands.
 */
final class RestateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/restate.php', 'restate');

        // Feature sub-providers (each self-contained, console-gating itself where relevant):
        // the `restate` queue connector, the make:restate-*/restate:codegen generators, the
        // Schedule::restate() macro, and observability (logging + optional Telescope).
        $this->app->register(RestateQueueServiceProvider::class);
        $this->app->register(RestateMakeServiceProvider::class);
        $this->app->register(CodegenServiceProvider::class);
        $this->app->register(RestateSchedulerServiceProvider::class);
        $this->app->register(RestateObservabilityServiceProvider::class);

        $this->app->singleton(RestateManager::class, static function (Application $app): RestateManager {
            $config = $app->make(Config::class)->get('restate', []);

            return new RestateManager($app, \is_array($config) ? $config : []);
        });

        $this->app->singleton(RequestProcessor::class, static function (Application $app): RequestProcessor {
            return $app->make(RestateManager::class)->processor();
        });

        // The client side: a singleton ingress dispatcher built from the `ingress` config,
        // sharing Laravel's HTTP client factory (so `Http::fake()` intercepts it in tests).
        $this->app->singleton(RestateClient::class, static function (Application $app): RestateClient {
            $ingress = $app->make(RestateManager::class)->ingressConfig();

            return new RestateClient(
                $app->make(HttpFactory::class),
                $ingress['url'],
                $ingress['token'],
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/restate.php' => $this->app->configPath('restate.php'),
            ], 'restate-config');

            $this->commands([ServeCommand::class, DiscoverCommand::class]);
        }

        $this->registerRoutes();
    }

    /**
     * Mounts the endpoint at the configured prefix as a catch-all so the runtime's
     * `/discover` and `/invoke/{service}/{handler}` paths all reach the controller. A null
     * prefix (config `path`) disables the in-app route — use `restate:serve` instead.
     */
    private function registerRoutes(): void
    {
        $manager = $this->app->make(RestateManager::class);
        $path = $manager->routePath();
        if ($path === null) {
            return;
        }

        Route::middleware($manager->routeMiddleware())
            ->prefix($path)
            ->group(static function (): void {
                Route::any('{restate_path?}', [EndpointController::class, 'handle'])
                    ->where('restate_path', '.*')
                    ->name('restate.endpoint');
            });
    }
}
