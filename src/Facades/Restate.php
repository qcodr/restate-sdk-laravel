<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Qcodr\Restate\Laravel\RestateManager;
use Qcodr\Restate\Laravel\Testing\RestateFake;

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

    /**
     * Fake all Restate dispatches (HTTP-layer, like Http::fake()) so feature tests assert
     * invocations without a runtime. See {@see RestateFake}.
     *
     * @param array<string, mixed>|null $result canned ingress result (default accepted send)
     */
    public static function fake(?string $ingressUrl = null, ?array $result = null): void
    {
        RestateFake::fake($ingressUrl, $result);
    }

    public static function assertCalled(string $service, string $handler, ?Closure $filter = null): void
    {
        RestateFake::assertCalled($service, $handler, $filter);
    }

    public static function assertSent(string $service, string $handler, ?Closure $filter = null): void
    {
        RestateFake::assertSent($service, $handler, $filter);
    }

    public static function assertCalledTimes(string $service, string $handler, int $times, ?Closure $filter = null): void
    {
        RestateFake::assertCalledTimes($service, $handler, $times, $filter);
    }

    public static function assertNothingDispatched(): void
    {
        RestateFake::assertNothingDispatched();
    }
}
