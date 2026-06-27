<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Auth;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\Context\Repository as ContextRepository;
use Stringable;

/**
 * OUTBOUND counterpart to {@see RestateContext}: builds the header array that propagates the
 * *current* Laravel identity (the authenticated user id and the active tenant) onto an
 * invocation dispatched from ordinary Laravel code, so the receiving handler can re-establish
 * them with {@see RestateContext::withAuth()}.
 *
 * The header names mirror the inbound side (same `restate.auth.*` config), so a value written
 * here is read back there unchanged. A guest with no tenant yields an empty array — nothing is
 * forwarded that was not actually set.
 *
 * --------------------------------------------------------------------------------------------
 * WIRING ASSUMPTION (not yet active)
 * --------------------------------------------------------------------------------------------
 * {@see \Qcodr\Restate\Laravel\Client\RestateClient} (a final class this package does not own
 * here) currently exposes no per-call custom-header parameter, so these headers cannot yet be
 * attached automatically. This class is the ready-to-wire half: once `RestateClient::call()` /
 * `::send()` gain a `?array $headers = null` parameter, the forwarding becomes a one-liner at
 * the call site:
 *
 * ```php
 * $restate->call('OrderService', 'place', $payload, headers: $forwarder->headers());
 * ```
 *
 * Until then, `headers()` is fully usable on its own (e.g. tests, a custom dispatcher, or a
 * generic raw call) — only the automatic attachment waits on that parameter.
 */
final class ForwardsAuthHeaders
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly ConfigRepository $config,
        private readonly ContextRepository $context,
    ) {
    }

    /**
     * The propagation headers for the current request's identity.
     *
     * Includes the user header only when a user is authenticated on the configured guard, and
     * the tenant header only when a scalar-identifiable tenant is present in Laravel's context.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];

        $userId = $this->currentUserId();
        if ($userId !== null) {
            $headers[$this->userHeader()] = $userId;
        }

        $tenantId = $this->currentTenantId();
        if ($tenantId !== null) {
            $headers[$this->tenantHeader()] = $tenantId;
        }

        return $headers;
    }

    /** The id of the user authenticated on the configured guard, as a string, or null for a guest. */
    private function currentUserId(): ?string
    {
        $id = $this->auth->guard($this->guardName())->id();

        return $id === null ? null : (string) $id;
    }

    /**
     * The active tenant id from Laravel's context, reduced to a scalar string. Rich tenant
     * objects (anything other than a string, int, Eloquent model, or {@see Stringable}) are not
     * forwarded automatically — an app using those should forward an explicit scalar id.
     */
    private function currentTenantId(): ?string
    {
        $key = $this->tenantContextKey();
        if (!$this->context->has($key)) {
            return null;
        }

        return $this->scalarTenant($this->context->get($key));
    }

    private function scalarTenant(mixed $value): ?string
    {
        if (\is_string($value)) {
            return $value === '' ? null : $value;
        }
        if (\is_int($value)) {
            return (string) $value;
        }
        if ($value instanceof Model) {
            $tenantKey = $value->getKey();

            return \is_string($tenantKey) || \is_int($tenantKey) ? (string) $tenantKey : null;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return null;
    }

    private function userHeader(): string
    {
        return \strtolower($this->stringConfig('restate.auth.user_header', RestateContext::DEFAULT_USER_HEADER));
    }

    private function tenantHeader(): string
    {
        return \strtolower($this->stringConfig('restate.auth.tenant_header', RestateContext::DEFAULT_TENANT_HEADER));
    }

    private function tenantContextKey(): string
    {
        return $this->stringConfig('restate.auth.tenant_context_key', RestateContext::DEFAULT_TENANT_CONTEXT_KEY);
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
}
