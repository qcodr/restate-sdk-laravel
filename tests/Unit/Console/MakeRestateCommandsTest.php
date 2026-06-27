<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Console;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Qcodr\Restate\Laravel\Discovery\RestateMakeServiceProvider;
use Qcodr\Restate\Laravel\RestateServiceProvider;
use Qcodr\Restate\Laravel\Tests\TestCase;

/**
 * Proves each `make:restate-*` generator writes a strict, attributed class into the app's
 * `App\Restate` namespace. The assertions read the generated file's contents (not just its
 * existence) so a regression in the stub — a missing attribute, a dropped `declare`, the
 * wrong input shape — fails loudly. Every generated file is cleaned up after each test.
 */
final class MakeRestateCommandsTest extends TestCase
{
    /**
     * @param Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RestateServiceProvider::class, RestateMakeServiceProvider::class];
    }

    protected function tearDown(): void
    {
        // Remove the generated app/Restate directory so each test starts from a clean slate
        // and nothing leaks into the next run.
        File::deleteDirectory(app_path('Restate'));

        parent::tearDown();
    }

    public function testMakeServiceGeneratesAttributedClass(): void
    {
        $this->assertGenerates('make:restate-service', 'Greeter', '#[Service]');
    }

    public function testMakeObjectGeneratesAttributedClass(): void
    {
        $this->assertGenerates('make:restate-object', 'Counter', '#[VirtualObject]');
    }

    public function testMakeWorkflowGeneratesAttributedClass(): void
    {
        $this->assertGenerates('make:restate-workflow', 'OrderSaga', '#[Workflow]');
    }

    /**
     * Runs a generator for $className and asserts the produced file lands at the expected
     * path with the right namespace, attribute, class declaration, and array-input handler.
     */
    private function assertGenerates(string $command, string $className, string $attribute): void
    {
        $path = app_path("Restate/{$className}.php");
        File::delete($path);

        // Run the generator eagerly: a PendingCommand defers execution to its destructor, so
        // we must call run() before asserting on the file it writes (an expectation-only call
        // would not have executed yet).
        $pending = $this->artisan($command, ['name' => $className]);
        self::assertInstanceOf(PendingCommand::class, $pending);
        self::assertSame(0, $pending->run());

        self::assertFileExists($path);

        $contents = File::get($path);
        self::assertStringContainsString('declare(strict_types=1);', $contents);
        self::assertStringContainsString('namespace App\\Restate;', $contents);
        self::assertStringContainsString($attribute, $contents);
        self::assertStringContainsString("final class {$className}", $contents);
        self::assertStringContainsString('?array $input', $contents);
    }
}
