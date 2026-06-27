<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Examples\Saga;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\InMemoryInventoryService;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\InMemoryPaymentService;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\InMemoryShippingService;
use Qcodr\Restate\Laravel\Tests\Examples\Saga\OrderWorkflow;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;
use Qcodr\Restate\Sdk\Endpoint\ProtocolMode;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;

/**
 * Proves the saga is a real, discoverable Restate workflow: binding the
 * {@see OrderWorkflow} into an endpoint and asking the processor for its manifest
 * advertises it as a WORKFLOW with a `run` handler. This is the same `GET /discover`
 * contract the Restate runtime uses to register a deployment, exercised here over the
 * transport-agnostic {@see RequestProcessor} with no Laravel boot.
 */
final class SagaDiscoveryTest extends TestCase
{
    private const MANIFEST_ACCEPT = 'application/vnd.restate.endpointmanifest.v1+json';

    private function processor(): RequestProcessor
    {
        $endpoint = Endpoint::builder()
            ->bind(new OrderWorkflow(
                new InMemoryInventoryService(['WIDGET' => 10]),
                new InMemoryPaymentService(),
                new InMemoryShippingService(),
            ))
            ->protocolMode(ProtocolMode::BidiStream)
            ->build();

        // The host can serve bidi, so discovery advertises BIDI_STREAM (the lesser of
        // the endpoint's mode and the transport capability).
        return new RequestProcessor($endpoint, transportCapability: ProtocolMode::BidiStream);
    }

    private function discover(): string
    {
        $response = $this->processor()->process(
            new HttpRequest('GET', '/discover', ['accept' => self::MANIFEST_ACCEPT], ''),
        );

        self::assertSame(200, $response->status);

        return $response->body;
    }

    public function testManifestListsTheWorkflowAndItsRunHandler(): void
    {
        $body = $this->discover();

        self::assertStringContainsString('OrderWorkflow', $body);
        self::assertStringContainsString('"name":"run"', $body);
    }

    public function testServiceTypeIsWorkflow(): void
    {
        self::assertStringContainsString('"ty":"WORKFLOW"', $this->discover());
    }

    public function testManifestAdvertisesBidiStreamWhenHostSupportsIt(): void
    {
        self::assertStringContainsString('"protocolMode":"BIDI_STREAM"', $this->discover());
    }
}
