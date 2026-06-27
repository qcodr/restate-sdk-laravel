<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit;

use Illuminate\Testing\PendingCommand;
use Qcodr\Restate\Laravel\RestateManager;
use Qcodr\Restate\Laravel\Tests\TestCase;

final class DiscoverCommandTest extends TestCase
{
    public function testListsBoundServices(): void
    {
        $command = $this->artisan('restate:discover');
        self::assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('GreeterService')->assertSuccessful();
    }

    public function testListsBoundServicesWithBulletsAndACount(): void
    {
        $command = $this->artisan('restate:discover');
        self::assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsOutputToContain('• ' . \Qcodr\Restate\Laravel\Tests\Support\GreeterService::class)
            ->expectsOutputToContain('1 service(s).')
            ->assertSuccessful();
    }

    public function testJsonPrintsTheManifest(): void
    {
        $command = $this->artisan('restate:discover', ['--json' => true]);
        self::assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('GreeterService')->assertSuccessful();
    }

    public function testWarnsAndSucceedsWhenNoServicesAreConfigured(): void
    {
        config()->set('restate.services', []);
        config()->set('restate.discover', null);
        app()->forgetInstance(RestateManager::class);

        $command = $this->artisan('restate:discover');
        self::assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('No services configured')->assertSuccessful();
    }
}
