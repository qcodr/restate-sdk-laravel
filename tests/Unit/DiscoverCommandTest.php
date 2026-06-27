<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit;

use Illuminate\Testing\PendingCommand;
use Qcodr\Restate\Laravel\Tests\TestCase;

final class DiscoverCommandTest extends TestCase
{
    public function testListsBoundServices(): void
    {
        $command = $this->artisan('restate:discover');
        self::assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('GreeterService')->assertSuccessful();
    }

    public function testJsonPrintsTheManifest(): void
    {
        $command = $this->artisan('restate:discover', ['--json' => true]);
        self::assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('GreeterService')->assertSuccessful();
    }
}
