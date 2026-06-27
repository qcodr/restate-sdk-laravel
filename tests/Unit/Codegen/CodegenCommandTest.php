<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Codegen;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Qcodr\Restate\Laravel\Codegen\CodegenServiceProvider;
use Qcodr\Restate\Laravel\RestateManager;
use Qcodr\Restate\Laravel\RestateServiceProvider;
use Qcodr\Restate\Laravel\Tests\Discovery\Fixtures\PlainClass;
use Qcodr\Restate\Laravel\Tests\TestCase;

/**
 * Proves `restate:codegen` writes a typed client for each configured service. The assertions
 * read the generated file's *contents* — namespace, class name, the `fromContext` factory,
 * the handler method, and the delegated `serviceCall` — so a regression in the wrapped SDK
 * generator or in the command's wiring fails loudly rather than silently producing an empty
 * or wrong file. Every generated file/directory is removed after each test so nothing leaks
 * into the next run. The configured fixture service is `GreeterService`, set by the base
 * {@see TestCase} via `restate.services`.
 */
final class CodegenCommandTest extends TestCase
{
    private string $customOutput;

    protected function setUp(): void
    {
        parent::setUp();

        // An absolute, app-local directory for the option test; created on demand by the
        // command (ClientWriter::ensureDirectoryExists) and torn down below.
        $this->customOutput = base_path('storage/framework/restate-codegen-test');
    }

    /**
     * @param Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // The codegen command lives in its own sub-provider; register it alongside the main
        // provider exactly as the application's RestateServiceProvider does in production.
        return [RestateServiceProvider::class, CodegenServiceProvider::class];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Restate'));
        File::deleteDirectory($this->customOutput);

        parent::tearDown();
    }

    public function testGeneratesTypedClientForConfiguredServiceByDefault(): void
    {
        $path = app_path('Restate/Clients/GreeterServiceClient.php');
        File::delete($path);

        // Run eagerly: a PendingCommand defers execution to its destructor, so run() must be
        // called before asserting on the file it writes.
        $pending = $this->artisan('restate:codegen');
        self::assertInstanceOf(PendingCommand::class, $pending);
        self::assertSame(0, $pending->run());

        self::assertFileExists($path);

        $contents = File::get($path);
        self::assertStringContainsString('declare(strict_types=1);', $contents);
        self::assertStringContainsString('namespace App\\Restate\\Clients;', $contents);
        self::assertStringContainsString('final class GreeterServiceClient', $contents);
        self::assertStringContainsString('public static function fromContext(Context $ctx): self', $contents);
        self::assertStringContainsString('public function greet(', $contents);
        self::assertStringContainsString("serviceCall('GreeterService', 'greet'", $contents);
    }

    public function testWarnsAndSucceedsWhenNoServicesAreConfigured(): void
    {
        config()->set('restate.services', []);
        config()->set('restate.discover', null);
        app()->forgetInstance(RestateManager::class);

        $pending = $this->artisan('restate:codegen');
        self::assertInstanceOf(PendingCommand::class, $pending);

        $pending->expectsOutputToContain('No services configured')->assertExitCode(0);
    }

    public function testReportsFailureWhenAServiceCannotBeGenerated(): void
    {
        // A plain (non-#[Service]) class makes the SDK generator throw; the command reports it
        // and exits non-zero while leaving the rest of the run intact.
        config()->set('restate.services', [PlainClass::class]);
        config()->set('restate.discover', null);
        app()->forgetInstance(RestateManager::class);

        $pending = $this->artisan('restate:codegen');
        self::assertInstanceOf(PendingCommand::class, $pending);

        $pending->expectsOutputToContain(PlainClass::class)->assertExitCode(1);
    }

    public function testUsesConfiguredNamespaceTrimmingSurroundingBackslashes(): void
    {
        $path = app_path('Restate/Clients/GreeterServiceClient.php');
        File::delete($path);

        config()->set('restate.codegen.namespace', '\\App\\Generated\\RestateClients\\');

        $pending = $this->artisan('restate:codegen');
        self::assertInstanceOf(PendingCommand::class, $pending);
        self::assertSame(0, $pending->run());

        self::assertFileExists($path);
        self::assertStringContainsString('namespace App\\Generated\\RestateClients;', File::get($path));
    }

    public function testHonoursOutputAndNamespaceOptions(): void
    {
        $namespace = 'App\\Generated\\RestateClients';
        $path = $this->customOutput . '/GreeterServiceClient.php';

        $pending = $this->artisan('restate:codegen', [
            '--output' => $this->customOutput,
            '--namespace' => $namespace,
        ]);
        self::assertInstanceOf(PendingCommand::class, $pending);
        self::assertSame(0, $pending->run());

        self::assertFileExists($path);

        $contents = File::get($path);
        self::assertStringContainsString('namespace App\\Generated\\RestateClients;', $contents);
        self::assertStringContainsString('final class GreeterServiceClient', $contents);
        self::assertStringContainsString("serviceCall('GreeterService', 'greet'", $contents);

        // The default location must stay untouched when an explicit output is given.
        self::assertFileDoesNotExist(app_path('Restate/Clients/GreeterServiceClient.php'));
    }
}
