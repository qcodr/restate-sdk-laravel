<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit;

use Qcodr\Restate\Laravel\Tests\TestCase;

final class EndpointControllerTest extends TestCase
{
    private const MANIFEST_ACCEPT = 'application/vnd.restate.endpointmanifest.v1+json';

    public function testDiscoveryReturnsManifestListingBoundService(): void
    {
        $response = $this->get('/restate/discover', ['Accept' => self::MANIFEST_ACCEPT]);

        $response->assertOk();
        $response->assertSee('GreeterService', false);
    }

    public function testHealthEndpointResponds(): void
    {
        // The runtime calls {deployment}/health; the catch-all routes it to the processor.
        $response = $this->get('/restate/health');

        $response->assertOk();
        $response->assertSee('OK', false);
    }

    public function testManifestAdvertisesRequestResponseOverTheInAppRoute(): void
    {
        // The endpoint opts into bidi, but the in-app request/response host caps discovery
        // back to REQUEST_RESPONSE (true bidi needs `restate:serve`).
        $response = $this->get('/restate/discover', ['Accept' => self::MANIFEST_ACCEPT]);

        $response->assertOk();
        $response->assertSee('REQUEST_RESPONSE', false);
    }
}
