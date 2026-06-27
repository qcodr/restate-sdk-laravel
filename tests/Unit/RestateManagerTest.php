<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit;

use Illuminate\Container\Container;
use Qcodr\Restate\Laravel\RestateManager;
use Qcodr\Restate\Laravel\Tests\Support\GreeterService;
use Qcodr\Restate\Laravel\Tests\TestCase;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;

final class RestateManagerTest extends TestCase
{
    public function testReadsConfiguredServiceClasses(): void
    {
        $manager = app(RestateManager::class);

        self::assertSame([GreeterService::class], $manager->serviceClasses());
    }

    public function testBuildsEndpointWithBoundServiceResolvedFromContainer(): void
    {
        $manager = app(RestateManager::class);

        $endpoint = $manager->endpoint();

        self::assertNotNull($endpoint->service('GreeterService'));
    }

    public function testEndpointIsMemoised(): void
    {
        $manager = app(RestateManager::class);

        self::assertSame($manager->endpoint(), $manager->endpoint());
    }

    public function testProcessorIsBuiltOverTheEndpoint(): void
    {
        $manager = app(RestateManager::class);

        self::assertInstanceOf(RequestProcessor::class, $manager->processor());
    }

    public function testIgnoresNonStringAndEmptyServiceEntries(): void
    {
        $manager = new RestateManager(new Container(), [
            'services' => [GreeterService::class, '', 123, null],
        ]);

        self::assertSame([GreeterService::class], $manager->serviceClasses());
    }

    public function testRoutePathDefaultsAndDisables(): void
    {
        self::assertSame('restate', (new RestateManager(new Container(), ['path' => 'restate']))->routePath());
        self::assertNull((new RestateManager(new Container(), ['path' => null]))->routePath());
        self::assertNull((new RestateManager(new Container(), ['path' => '']))->routePath());
    }

    public function testRouteMiddlewareFallsBackToApi(): void
    {
        self::assertSame(['api'], (new RestateManager(new Container(), []))->routeMiddleware());
        self::assertSame(['web', 'auth'], (new RestateManager(new Container(), [
            'middleware' => ['web', 'auth'],
        ]))->routeMiddleware());
    }

    public function testServerConfigCoercesTypes(): void
    {
        $manager = new RestateManager(new Container(), [
            'server' => ['host' => '127.0.0.1', 'port' => '9090', 'workers' => '4'],
        ]);

        self::assertSame(['host' => '127.0.0.1', 'port' => 9090, 'workers' => 4], $manager->serverConfig());
    }

    public function testServerConfigDefaults(): void
    {
        $manager = new RestateManager(new Container(), []);

        self::assertSame(['host' => '0.0.0.0', 'port' => 9080, 'workers' => 1], $manager->serverConfig());
    }
}
