<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit;

use Illuminate\Container\Container;
use Qcodr\Restate\Laravel\RestateManager;
use Qcodr\Restate\Laravel\Tests\Discovery\Fixtures\FixtureObject;
use Qcodr\Restate\Laravel\Tests\Discovery\Fixtures\FixtureService;
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

    public function testReindexesServiceClassesAfterFilteringLeadingInvalidEntries(): void
    {
        // Invalid entries first so the surviving class lands at a non-zero key; the result must
        // be re-keyed to a clean 0-based list.
        $manager = new RestateManager(new Container(), [
            'services' => ['', 123, GreeterService::class],
        ]);

        self::assertSame([GreeterService::class], $manager->serviceClasses());
    }

    public function testMergesDiscoveredServicesWithExplicitOnesUniquelyAndReindexed(): void
    {
        // FixtureObject is also listed explicitly, so it is the de-dup target: the merged list
        // must drop the duplicate and re-key to a clean 0-based list.
        $manager = new RestateManager(new Container(), [
            'services' => [FixtureObject::class],
            'discover' => \dirname(__DIR__) . '/Discovery/Fixtures',
            'discover_namespace' => 'Qcodr\\Restate\\Laravel\\Tests\\Discovery\\Fixtures',
        ]);

        self::assertSame([FixtureObject::class, FixtureService::class], $manager->serviceClasses());
    }

    public function testDiscoveryIsSkippedWhenNoDiscoverPathIsConfigured(): void
    {
        $manager = new RestateManager(new Container(), [
            'services' => [GreeterService::class],
            'discover' => '',
        ]);

        self::assertSame([GreeterService::class], $manager->serviceClasses());
    }

    public function testEndpointEnablesIdentityVerificationWhenAKeyIsConfigured(): void
    {
        $manager = new RestateManager(new Container(), [
            'services' => [GreeterService::class],
            'identity_key' => 'publickeyv1_w7YHemBctH5Ck2nQRQ47iBBqhNHy4FV7t2Usbye2A6f',
        ]);

        self::assertNotNull($manager->endpoint()->identityVerifier());
    }

    public function testEndpointHasNoIdentityVerifierWithoutAKey(): void
    {
        $manager = new RestateManager(new Container(), [
            'services' => [GreeterService::class],
        ]);

        self::assertNull($manager->endpoint()->identityVerifier());
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

    public function testRouteMiddlewareFallsBackToApiWhenConfigIsNotAnArray(): void
    {
        // A scalar `middleware` config is not a list of groups; the route falls back to `api`.
        self::assertSame(['api'], (new RestateManager(new Container(), [
            'middleware' => 'web',
        ]))->routeMiddleware());
    }

    public function testRouteMiddlewareFiltersNonStringEntriesAndReindexes(): void
    {
        // A non-string sandwiched between strings is dropped and the list re-keyed to 0-based.
        self::assertSame(['web', 'auth'], (new RestateManager(new Container(), [
            'middleware' => ['web', 123, 'auth'],
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

    public function testServerConfigFallsBackForNonNumericAndNonStringValues(): void
    {
        // A non-string host and non-numeric port/workers exercise the inner fallbacks (9080/1),
        // distinct from the defaults path where the keys are absent entirely.
        $manager = new RestateManager(new Container(), [
            'server' => ['host' => 123, 'port' => 'not-a-port', 'workers' => 'lots'],
        ]);

        self::assertSame(['host' => '0.0.0.0', 'port' => 9080, 'workers' => 1], $manager->serverConfig());
    }

    public function testIngressConfigDefaultsToLocalRuntimeWithoutToken(): void
    {
        $manager = new RestateManager(new Container(), []);

        self::assertSame(['url' => 'http://localhost:8080', 'token' => null], $manager->ingressConfig());
    }

    public function testIngressConfigReadsUrlAndToken(): void
    {
        $manager = new RestateManager(new Container(), [
            'ingress' => ['url' => 'https://ingress.example.com', 'token' => 'tok'],
        ]);

        self::assertSame(
            ['url' => 'https://ingress.example.com', 'token' => 'tok'],
            $manager->ingressConfig(),
        );
    }

    public function testIngressConfigNormalisesEmptyValues(): void
    {
        $manager = new RestateManager(new Container(), [
            'ingress' => ['url' => '', 'token' => ''],
        ]);

        self::assertSame(['url' => 'http://localhost:8080', 'token' => null], $manager->ingressConfig());
    }
}
