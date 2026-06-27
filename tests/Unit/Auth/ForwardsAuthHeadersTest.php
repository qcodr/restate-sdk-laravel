<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Auth;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Qcodr\Restate\Laravel\Auth\ForwardsAuthHeaders;
use Qcodr\Restate\Laravel\Tests\TestCase;

/**
 * Drives the OUTBOUND helper {@see ForwardsAuthHeaders::headers()}: it must translate the
 * current Laravel identity (authenticated user + active tenant) into the propagation header map
 * a dispatch should carry, and produce nothing for a guest with no tenant.
 *
 * The header names match the inbound side, so a value produced here is read back unchanged by
 * {@see \Qcodr\Restate\Laravel\Auth\RestateContext}.
 */
final class ForwardsAuthHeadersTest extends TestCase
{
    public function testProducesUserHeaderForAuthenticatedUser(): void
    {
        Auth::guard('web')->setUser(new FakeUser('42'));

        self::assertSame(['x-restate-user' => '42'], $this->forwarder()->headers());
    }

    public function testGuestProducesNoHeaders(): void
    {
        self::assertSame([], $this->forwarder()->headers());
    }

    public function testIncludesTenantHeaderFromContext(): void
    {
        Context::add('restate.tenant', 'acme');

        self::assertSame(['x-restate-tenant' => 'acme'], $this->forwarder()->headers());
    }

    public function testForwardsUserAndTenantTogether(): void
    {
        Auth::guard('web')->setUser(new FakeUser('42'));
        Context::add('restate.tenant', 'acme');

        self::assertSame(
            ['x-restate-user' => '42', 'x-restate-tenant' => 'acme'],
            $this->forwarder()->headers(),
        );
    }

    public function testHonoursConfiguredHeaderNames(): void
    {
        config()->set('restate.auth.user_header', 'X-Acme-User');
        config()->set('restate.auth.tenant_header', 'X-Acme-Tenant');
        Auth::guard('web')->setUser(new FakeUser('42'));
        Context::add('restate.tenant', 'acme');

        self::assertSame(
            ['x-acme-user' => '42', 'x-acme-tenant' => 'acme'],
            $this->forwarder()->headers(),
        );
    }

    public function testReducesAnIntegerTenantToItsStringForm(): void
    {
        Context::add('restate.tenant', 42);

        self::assertSame(['x-restate-tenant' => '42'], $this->forwarder()->headers());
    }

    public function testReducesAnEloquentModelTenantToItsIntegerKey(): void
    {
        Context::add('restate.tenant', new FakeTenantModel(['id' => 99]));

        self::assertSame(['x-restate-tenant' => '99'], $this->forwarder()->headers());
    }

    public function testReducesAnEloquentModelTenantToItsStringKey(): void
    {
        Context::add('restate.tenant', new FakeTenantModel(['id' => 'tenant-uuid']));

        self::assertSame(['x-restate-tenant' => 'tenant-uuid'], $this->forwarder()->headers());
    }

    public function testForwardsNoTenantHeaderForAModelWithoutAScalarKey(): void
    {
        // A model whose key attribute is unset returns null from getKey(); nothing scalar to
        // forward, so the tenant header is omitted entirely.
        Context::add('restate.tenant', new FakeTenantModel());

        self::assertSame([], $this->forwarder()->headers());
    }

    public function testReducesAStringableTenantToItsStringForm(): void
    {
        Context::add('restate.tenant', new StringableTenant('acme-stringable'));

        self::assertSame(['x-restate-tenant' => 'acme-stringable'], $this->forwarder()->headers());
    }

    public function testForwardsNoTenantHeaderForANonScalarTenant(): void
    {
        // A float is neither a string/int, nor a Model, nor Stringable: not forwardable, so the
        // tenant header is omitted rather than coerced.
        Context::add('restate.tenant', 1.5);

        self::assertSame([], $this->forwarder()->headers());
    }

    public function testForwardsNoTenantHeaderForAnEmptyStringTenant(): void
    {
        Context::add('restate.tenant', '');

        self::assertSame([], $this->forwarder()->headers());
    }

    private function forwarder(): ForwardsAuthHeaders
    {
        /** @var Application $app */
        $app = $this->app;

        return $app->make(ForwardsAuthHeaders::class);
    }
}
