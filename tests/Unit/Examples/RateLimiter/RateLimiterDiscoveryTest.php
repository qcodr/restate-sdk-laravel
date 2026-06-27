<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Examples\RateLimiter;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Laravel\Tests\Examples\RateLimiter\RateLimiterObject;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;
use Qcodr\Restate\Sdk\Endpoint\ProtocolMode;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;

/**
 * Proves the {@see RateLimiterObject} is a well-formed Restate Virtual Object by building
 * an {@see Endpoint} around it and exercising the real discovery route — the same manifest
 * the Restate runtime fetches when registering the deployment. No HTTP server, no Laravel:
 * just the SDK's transport-agnostic {@see RequestProcessor}.
 */
final class RateLimiterDiscoveryTest extends TestCase
{
    private const MANIFEST_ACCEPT = 'application/vnd.restate.endpointmanifest.v1+json';

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $endpoint = Endpoint::builder()
            ->bind(new RateLimiterObject())
            ->protocolMode(ProtocolMode::BidiStream)
            ->build();
        $processor = new RequestProcessor($endpoint);

        $response = $processor->process(new HttpRequest(
            'GET',
            '/discover',
            ['accept' => self::MANIFEST_ACCEPT],
            '',
        ));

        self::assertSame(200, $response->status);

        $decoded = \json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testDiscoveryListsTheVirtualObjectService(): void
    {
        $service = $this->serviceEntry();

        self::assertSame('RateLimiterObject', $service['name']);
        self::assertSame('VIRTUAL_OBJECT', $service['ty']);
    }

    public function testDiscoveryAdvertisesAllThreeHandlersWithCorrectTypes(): void
    {
        $handlers = $this->handlerTypes();

        self::assertArrayHasKey('hit', $handlers);
        self::assertArrayHasKey('reset', $handlers);
        self::assertArrayHasKey('peek', $handlers);

        // Exclusive (single-writer) handlers may mutate state; the shared one is read-only.
        self::assertSame('EXCLUSIVE', $handlers['hit']);
        self::assertSame('EXCLUSIVE', $handlers['reset']);
        self::assertSame('SHARED', $handlers['peek']);
    }

    public function testManifestBodyMentionsTheServiceAndHandlers(): void
    {
        $endpoint = Endpoint::builder()->bind(new RateLimiterObject())->build();
        $processor = new RequestProcessor($endpoint);

        $body = $processor->process(new HttpRequest(
            'GET',
            '/discover',
            ['accept' => self::MANIFEST_ACCEPT],
            '',
        ))->body;

        self::assertStringContainsString('RateLimiterObject', $body);
        self::assertStringContainsString('hit', $body);
        self::assertStringContainsString('reset', $body);
        self::assertStringContainsString('peek', $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceEntry(): array
    {
        $manifest = $this->manifest();
        self::assertArrayHasKey('services', $manifest);
        $services = $manifest['services'];
        self::assertIsArray($services);
        self::assertArrayHasKey(0, $services);

        $service = $services[0];
        self::assertIsArray($service);

        /** @var array<string, mixed> $service */
        return $service;
    }

    /**
     * @return array<string, string> handler name => handler type
     */
    private function handlerTypes(): array
    {
        $service = $this->serviceEntry();
        self::assertArrayHasKey('handlers', $service);
        $handlers = $service['handlers'];
        self::assertIsArray($handlers);

        $types = [];
        foreach ($handlers as $handler) {
            self::assertIsArray($handler);
            self::assertArrayHasKey('name', $handler);
            self::assertArrayHasKey('ty', $handler);
            $name = $handler['name'];
            $type = $handler['ty'];
            self::assertIsString($name);
            self::assertIsString($type);
            $types[$name] = $type;
        }

        return $types;
    }
}
