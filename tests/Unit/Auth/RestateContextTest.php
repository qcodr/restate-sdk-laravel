<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Auth;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Qcodr\Restate\Laravel\Auth\RestateContext;
use Qcodr\Restate\Laravel\Tests\TestCase;
use RuntimeException;

/**
 * Drives the INBOUND helper {@see RestateContext::withAuth()}: given a {@see Context} whose
 * forwarded request headers carry a propagated user / tenant id, it must bind Laravel's auth and
 * tenant state for the wrapped work and restore the prior state afterwards.
 *
 * The guard is pointed at an in-memory {@see FakeUserProvider} so a header id resolves to a
 * {@see FakeUser} with no database or Eloquent model.
 */
final class RestateContextTest extends TestCase
{
    private const TENANT_CONTEXT_KEY = 'restate.tenant';

    protected function setUp(): void
    {
        parent::setUp();

        // Point the default `web` guard at an in-memory provider so `onceUsingId()` resolves
        // header ids to FakeUsers without touching a database.
        Auth::provider('restate-fake', static fn (): FakeUserProvider => new FakeUserProvider([
            '42' => new FakeUser('42'),
            '7' => new FakeUser('7'),
        ]));

        config()->set('auth.providers.restate-users', ['driver' => 'restate-fake']);
        config()->set('auth.guards.web.provider', 'restate-users');
        config()->set('auth.defaults.guard', 'web');
    }

    public function testResolvesUserFromHeaderAndRestoresGuestAfter(): void
    {
        $ctx = new RequestHeadersContext(['x-restate-user' => '42']);

        $seenId = null;
        $result = $this->restate()->withAuth($ctx, static function () use (&$seenId): string {
            $seenId = auth()->id();

            return 'work-result';
        });

        self::assertSame('42', $seenId, 'the propagated user is authenticated inside the callback');
        self::assertSame('work-result', $result, 'the callback return value passes straight through');
        self::assertNull(auth()->id(), 'auth is restored to guest after the callback');
        self::assertTrue(auth()->guest());
    }

    public function testMissingUserHeaderLeavesAuthUntouchedAsGuest(): void
    {
        $ctx = new RequestHeadersContext([]);

        $seenId = 'sentinel';
        $this->restate()->withAuth($ctx, static function () use (&$seenId): void {
            $seenId = auth()->id();
        });

        self::assertNull($seenId, 'no user header means the handler runs as a guest');
        self::assertTrue(auth()->guest());
    }

    public function testRestoresPreExistingUserAfterPropagation(): void
    {
        // A user already authenticated on the guard before the handler runs must come back
        // unchanged afterwards — the propagated identity is scoped to the callback only.
        Auth::guard('web')->setUser(new FakeUser('7'));
        self::assertSame('7', auth()->id());

        $ctx = new RequestHeadersContext(['x-restate-user' => '42']);

        $seenId = null;
        $this->restate()->withAuth($ctx, static function () use (&$seenId): void {
            $seenId = auth()->id();
        });

        self::assertSame('42', $seenId, 'the propagated user is active during the callback');
        self::assertSame('7', auth()->id(), 'the pre-existing user is restored after the callback');
    }

    public function testHonoursConfiguredUserHeaderName(): void
    {
        config()->set('restate.auth.user_header', 'X-Acme-User');
        $ctx = new RequestHeadersContext(['x-acme-user' => '42']);

        $seenId = null;
        $this->restate()->withAuth($ctx, static function () use (&$seenId): void {
            $seenId = auth()->id();
        });

        self::assertSame('42', $seenId);
    }

    public function testBindsTenantIntoContextAndForgetsAfter(): void
    {
        $ctx = new RequestHeadersContext(['x-restate-tenant' => 'acme']);

        $seenTenant = null;
        $this->restate()->withAuth($ctx, static function () use (&$seenTenant): void {
            $seenTenant = Context::get(self::TENANT_CONTEXT_KEY);
        });

        self::assertSame('acme', $seenTenant, 'the tenant is readable from Laravel context inside the callback');
        self::assertFalse(Context::has(self::TENANT_CONTEXT_KEY), 'the tenant key is forgotten afterwards');
    }

    public function testRestoresPriorTenantContextValue(): void
    {
        Context::add(self::TENANT_CONTEXT_KEY, 'original-tenant');
        $ctx = new RequestHeadersContext(['x-restate-tenant' => 'acme']);

        $seenTenant = null;
        $this->restate()->withAuth($ctx, static function () use (&$seenTenant): void {
            $seenTenant = Context::get(self::TENANT_CONTEXT_KEY);
        });

        self::assertSame('acme', $seenTenant);
        self::assertSame('original-tenant', Context::get(self::TENANT_CONTEXT_KEY), 'the prior tenant value is restored');
    }

    public function testRunsTheConfiguredTenantResolver(): void
    {
        config()->set('restate.auth.tenant_resolver', static fn (string $id): string => 'tenant:' . $id);
        $ctx = new RequestHeadersContext(['x-restate-tenant' => 'acme']);

        $seenTenant = null;
        $this->restate()->withAuth($ctx, static function () use (&$seenTenant): void {
            $seenTenant = Context::get(self::TENANT_CONTEXT_KEY);
        });

        self::assertSame('tenant:acme', $seenTenant, 'the resolver maps the raw id before it is bound into context');
    }

    public function testResolvesTenantThroughAContainerResolvableInvokableResolver(): void
    {
        // A class-string resolver is pulled from the container and invoked with the raw id.
        config()->set('restate.auth.tenant_resolver', InvokableTenantResolver::class);
        $ctx = new RequestHeadersContext(['x-restate-tenant' => 'acme']);

        $seenTenant = null;
        $this->restate()->withAuth($ctx, static function () use (&$seenTenant): void {
            $seenTenant = Context::get(self::TENANT_CONTEXT_KEY);
        });

        self::assertSame('resolved:acme', $seenTenant);
    }

    public function testThrowsWhenTheTenantResolverIsNeitherCallableNorString(): void
    {
        config()->set('restate.auth.tenant_resolver', 42);
        $ctx = new RequestHeadersContext(['x-restate-tenant' => 'acme']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('restate.auth.tenant_resolver must be a callable');

        $this->restate()->withAuth($ctx, static fn (): null => null);
    }

    public function testThrowsWhenTheTenantResolverClassStringIsNotInvokable(): void
    {
        config()->set('restate.auth.tenant_resolver', NonInvokableResolver::class);
        $ctx = new RequestHeadersContext(['x-restate-tenant' => 'acme']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('restate.auth.tenant_resolver must be a callable');

        $this->restate()->withAuth($ctx, static fn (): null => null);
    }

    public function testThrowsWhenTheConfiguredGuardIsNotStateful(): void
    {
        // A token guard is a Guard but not a StatefulGuard, so a propagated user id cannot be
        // resolved through it; the helper fails fast and names the offending guard.
        config()->set('auth.guards.restate-token', ['driver' => 'token', 'provider' => 'restate-users']);
        config()->set('restate.auth.guard', 'restate-token');

        $ctx = new RequestHeadersContext(['x-restate-user' => '42']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('restate-token');

        $this->restate()->withAuth($ctx, static fn (): null => null);
    }

    public function testHeaderLookupIsCaseInsensitiveForUserAndTenant(): void
    {
        // HTTP header names are case-insensitive; a runtime may surface them mixed-case. Both
        // the user and the tenant must still resolve, and both must survive the lowercasing.
        $ctx = new RequestHeadersContext([
            'X-Restate-User' => '42',
            'X-Restate-Tenant' => 'acme',
        ]);

        $seenId = null;
        $seenTenant = null;
        $this->restate()->withAuth($ctx, static function () use (&$seenId, &$seenTenant): void {
            $seenId = auth()->id();
            $seenTenant = Context::get(self::TENANT_CONTEXT_KEY);
        });

        self::assertSame('42', $seenId);
        self::assertSame('acme', $seenTenant);
    }

    private function restate(): RestateContext
    {
        /** @var Application $app */
        $app = $this->app;

        return $app->make(RestateContext::class);
    }
}
