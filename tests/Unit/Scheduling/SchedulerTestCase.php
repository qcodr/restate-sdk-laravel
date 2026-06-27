<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Scheduling;

use Illuminate\Foundation\Application;
use Qcodr\Restate\Laravel\RestateServiceProvider;
use Qcodr\Restate\Laravel\Scheduling\RestateSchedulerServiceProvider;
use Qcodr\Restate\Laravel\Tests\TestCase;

/**
 * Shared base for the scheduler tests: boots the package together with the scheduler
 * sub-provider (which the main provider does not auto-register here), so the `Schedule::restate()`
 * macro is registered for every case.
 */
abstract class SchedulerTestCase extends TestCase
{
    /**
     * @param Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RestateServiceProvider::class, RestateSchedulerServiceProvider::class];
    }
}
