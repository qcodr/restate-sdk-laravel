<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Qcodr\Restate\Laravel\RestateManager;

/**
 * Facade for the {@see RestateManager}.
 *
 * @method static \Qcodr\Restate\Sdk\Endpoint\Endpoint endpoint()
 * @method static \Qcodr\Restate\Sdk\Endpoint\RequestProcessor processor()
 * @method static \Qcodr\Restate\Laravel\Client\RestateClient client()
 * @method static list<string> serviceClasses()
 * @method static string|null routePath()
 * @method static list<string> routeMiddleware()
 * @method static array{url: string, token: string|null} ingressConfig()
 * @method static array{host: string, port: int, workers: int} serverConfig()
 *
 * @see RestateManager
 */
final class Restate extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RestateManager::class;
    }
}
