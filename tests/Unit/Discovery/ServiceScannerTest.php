<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Discovery;

use Illuminate\Foundation\Application;
use Qcodr\Restate\Laravel\Discovery\RestateMakeServiceProvider;
use Qcodr\Restate\Laravel\Discovery\ServiceScanner;
use Qcodr\Restate\Laravel\RestateServiceProvider;
use Qcodr\Restate\Laravel\Tests\Discovery\Fixtures\FixtureObject;
use Qcodr\Restate\Laravel\Tests\Discovery\Fixtures\FixtureService;
use Qcodr\Restate\Laravel\Tests\TestCase;

/**
 * Pins the discovery contract: over a fixture directory holding one `#[Service]`, one
 * `#[VirtualObject]`, and one plain class, the scanner returns exactly the two attributed
 * FQCNs, sorted, with the plain class excluded — the deterministic input RestateManager
 * relies on to auto-register handlers from a `discover` path.
 */
final class ServiceScannerTest extends TestCase
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

    public function testReturnsOnlyAttributedClassesSorted(): void
    {
        $scanner = new ServiceScanner();
        $directory = \dirname(__DIR__, 2) . '/Discovery/Fixtures';

        $found = $scanner->scan($directory, 'Qcodr\\Restate\\Laravel\\Tests\\Discovery\\Fixtures');

        // FixtureObject sorts before FixtureService; PlainClass is absent (no attribute).
        self::assertSame([FixtureObject::class, FixtureService::class], $found);
    }

    public function testMissingDirectoryReturnsEmptyList(): void
    {
        $scanner = new ServiceScanner();

        self::assertSame([], $scanner->scan('/no/such/restate/discover/path', 'App\\Restate'));
    }
}
