<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Qcodr\Restate\Laravel\RestateServiceProvider;
use Qcodr\Restate\Laravel\Tests\Support\GreeterService;

abstract class TestCase extends Orchestra
{
    /**
     * @param Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RestateServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);
        $config->set('restate.services', [GreeterService::class]);
        $config->set('restate.path', 'restate');
    }
}
