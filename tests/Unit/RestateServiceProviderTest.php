<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use Qcodr\Restate\Laravel\RestateManager;
use Qcodr\Restate\Laravel\Tests\TestCase;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;

final class RestateServiceProviderTest extends TestCase
{
    public function testBindsManagerAsSingleton(): void
    {
        $first = app(RestateManager::class);
        $second = app(RestateManager::class);

        self::assertInstanceOf(RestateManager::class, $first);
        self::assertSame($first, $second);
    }

    public function testBindsRequestProcessor(): void
    {
        self::assertInstanceOf(RequestProcessor::class, app(RequestProcessor::class));
    }

    public function testRegistersTheEndpointRoute(): void
    {
        self::assertTrue(Route::has('restate.endpoint'));
    }

    public function testMergesPackageDefaultConfig(): void
    {
        $config = app(Repository::class);

        self::assertSame(9080, $config->get('restate.server.port'));
        self::assertSame(1, $config->get('restate.server.workers'));
    }
}
