<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Auth;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Log\Context\Repository as ContextRepository;
use Qcodr\Restate\Sdk\Context\Context;
use RuntimeException;

/**
 * Re-establishes the authenticated user and the active tenant *inside* a Restate handler.
 *
 * A Restate invocation runs detached from the original HTTP request: there is no session, no
 * cookie, no middleware stack — so Laravel's auth/tenancy state starts empty even when the
 * caller was authenticated. The Restate runtime, however, forwards the caller's request
 * headers to the handler (reachable via {@see Context::requestHeaders()}). This helper reads a
 * propagated user id / tenant id from those headers and binds them into Laravel for the
 * duration of one unit of work, so ordinary handler code can call `auth()->user()`,
 * `auth()->id()`, or read the tenant from Laravel's {@see ContextRepository} as usual.
 *
 * State is established for the {@see withAuth()} callback only and restored afterwards: the
 * prior guard user and the prior tenant context value are captured up front and put back in a
 * `finally`, so a handler that runs several units of work (or a worker that processes several
 * invocations on one booted app) never leaks one invocation's identity into the next.
 *
 * The header names, the guard, the tenant context key, and an optional tenant resolver are all
 * configuration-driven (see the `restate.auth.*` keys documented in {@see self} constants and
 * in docs/usecases/auth.md); sensible defaults apply when they are absent.
 */
final class RestateContext
{
    /** Config key `restate.auth.user_header`: request header carrying the propagated user id. */
    public const DEFAULT_USER_HEADER = 'x-restate-user';

    /** Config key `restate.auth.tenant_header`: request header carrying the propagated tenant id. */
    public const DEFAULT_TENANT_HEADER = 'x-restate-tenant';

    /** Config key `restate.auth.tenant_context_key`: Laravel context key the tenant is bound under. */
    public const DEFAULT_TENANT_CONTEXT_KEY = 'restate.tenant';

    public function __construct(
        private readonly AuthManager $auth,
        private readonly ConfigRepository $config,
        private readonly ContextRepository $context,
    ) {
    }

    /**
     * Runs `$work` with the propagated identity bound, restoring the prior state afterwards.
     *
     * The return value of `$work` is passed straight through, so a handler can wrap its body:
     *
     * ```php
     * return $restate->withAuth($ctx, fn () => $this->process($order));
     * ```
     *
     * @template T
     *
     * @param callable():T $work
     *
     * @return T
     */
    public function withAuth(Context $ctx, callable $work): mixed
    {
        $headers = $this->lowercaseHeaders($ctx->requestHeaders());

        $restoreUser = $this->establishUser($headers);
        $restoreTenant = $this->establishTenant($headers);

        try {
            return $work();
        } finally {
            // Reverse order of establishment: tenant first, then auth, so each restorer sees
            // the same world it captured.
            $restoreTenant();
            $restoreUser();
        }
    }

    /**
     * Binds the propagated user onto the configured guard and returns a closure that restores
     * the guard to exactly the state it had before.
     *
     * When the header is absent the guard is left untouched (a no-op restorer): an unauthenticated
     * caller stays a guest. When present, the id is resolved through the guard's own user provider
     * via {@see StatefulGuard::onceUsingId()} — which loads the user and sets it on the guard
     * *without* touching any session — so `auth()->user()` returns it for the callback's duration.
     *
     * @param array<string, string> $headers lower-cased request header map
     */
    private function establishUser(array $headers): Closure
    {
        $headerName = \strtolower($this->stringConfig('restate.auth.user_header', self::DEFAULT_USER_HEADER));
        $id = $headers[$headerName] ?? null;
        if ($id === null || $id === '') {
            return static function (): void {
            };
        }

        $guardName = $this->guardName();
        $guard = $this->auth->guard($guardName);
        if (!$guard instanceof StatefulGuard) {
            throw new RuntimeException(\sprintf(
                'Restate auth propagation needs a stateful guard to resolve the "%s" header into a user; '
                . 'the configured guard (%s) is not stateful. Point restate.auth.guard at a session-style guard.',
                $headerName,
                $guardName ?? 'default',
            ));
        }

        // Capture the in-memory user only — never trigger a session/remember-cookie load, which
        // would fail in a detached handler. A handler normally starts as a guest, so this is null.
        $prior = $guard->hasUser() ? $guard->user() : null;
        $guard->onceUsingId($id);

        return function () use ($guardName, $prior): void {
            // Drop the guard instance we mutated so the next resolution starts clean (guest);
            // then re-pin any user that was already set before we ran.
            $this->auth->forgetGuards();
            if ($prior !== null) {
                $this->auth->guard($guardName)->setUser($prior);
            }
        };
    }

    /**
     * Binds the propagated tenant into Laravel's {@see ContextRepository} and returns a closure
     * that restores the prior context value.
     *
     * The raw header value is passed through an optional resolver (`restate.auth.tenant_resolver`)
     * before being stored, so an app can map a tenant id onto a richer value (a model, a settings
     * object). With no resolver the raw id string is stored, which round-trips cleanly back out
     * through {@see ForwardsAuthHeaders}.
     *
     * @param array<string, string> $headers lower-cased request header map
     */
    private function establishTenant(array $headers): Closure
    {
        $headerName = \strtolower($this->stringConfig('restate.auth.tenant_header', self::DEFAULT_TENANT_HEADER));
        $tenantId = $headers[$headerName] ?? null;
        if ($tenantId === null || $tenantId === '') {
            return static function (): void {
            };
        }

        $resolver = $this->tenantResolver();
        $value = $resolver !== null ? $resolver($tenantId) : $tenantId;

        $key = $this->stringConfig('restate.auth.tenant_context_key', self::DEFAULT_TENANT_CONTEXT_KEY);
        $had = $this->context->has($key);
        $prior = $had ? $this->context->get($key) : null;
        $this->context->add($key, $value);

        return function () use ($key, $had, $prior): void {
            if ($had) {
                $this->context->add($key, $prior);
            } else {
                $this->context->forget($key);
            }
        };
    }

    /**
     * Resolves the optional tenant resolver from config into a callable.
     *
     * Accepts a callable directly, or a container-resolvable invokable class-string (an
     * `__invoke(string $tenantId): mixed` object). Fails fast on a misconfigured value rather
     * than silently ignoring it.
     */
    private function tenantResolver(): ?callable
    {
        $resolver = $this->config->get('restate.auth.tenant_resolver');
        if ($resolver === null) {
            return null;
        }
        if (\is_callable($resolver)) {
            return $resolver;
        }
        if (\is_string($resolver)) {
            $instance = app($resolver);
            if (\is_callable($instance)) {
                return $instance;
            }
        }

        throw new RuntimeException(
            'restate.auth.tenant_resolver must be a callable or a container-resolvable invokable class-string.',
        );
    }

    /** The configured guard name, or null to use Laravel's default guard. */
    private function guardName(): ?string
    {
        $guard = $this->config->get('restate.auth.guard');

        return \is_string($guard) && $guard !== '' ? $guard : null;
    }

    /** Reads a string config value, falling back to the default when absent or not a string. */
    private function stringConfig(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return \is_string($value) ? $value : $default;
    }

    /**
     * Lower-cases the header map keys so lookups are case-insensitive regardless of how the SDK
     * surfaces them (HTTP header names are case-insensitive per RFC 7230 §3.2).
     *
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function lowercaseHeaders(array $headers): array
    {
        $lowered = [];
        foreach ($headers as $name => $value) {
            $lowered[\strtolower($name)] = $value;
        }

        return $lowered;
    }
}
