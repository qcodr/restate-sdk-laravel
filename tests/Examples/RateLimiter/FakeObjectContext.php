<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Examples\RateLimiter;

use Qcodr\Restate\Sdk\Context\ObjectContext;
use Qcodr\Restate\Sdk\Serde\JsonSerde;
use Qcodr\Restate\Sdk\Serde\Serde;

/**
 * An in-memory {@see ObjectContext} test double, so the rate-limiter handlers can be
 * driven end-to-end with no Restate runtime.
 *
 * Fidelity matters here: this fake stores state the same way the real
 * {@see \Qcodr\Restate\Sdk\Context\RestateContext} does — serialised through {@see Serde}
 * on `set` and deserialised back on `get` (with a missing key reading as `null`). That is
 * what lets these tests prove a real property: a {@see TokenBucket} state map written as
 * `{tokens: 4.0, ...}` survives the JSON round-trip (coming back with `tokens` as the
 * integer `4`) and still rebuilds correctly. A naive array-backed fake would hide that.
 *
 * It is intentionally single-key: in Restate each object key has its own isolated state,
 * so a separate instance models a separate key — which is exactly how the tests prove
 * per-key isolation.
 *
 * Beyond the SDK contract it exposes a couple of read-only affordances ({@see writeCount},
 * {@see snapshot}, {@see has}) so a test can assert how the handler touched state — most
 * importantly that the shared `peek` handler mutates nothing.
 */
final class FakeObjectContext implements ObjectContext
{
    use UnsupportedContextMethods;

    private readonly Serde $serde;

    /**
     * Serialised state, keyed exactly as the real context journals it.
     *
     * @var array<string, string>
     */
    private array $state = [];

    private int $writes = 0;

    /**
     * @param array<string, mixed> $initialState seed values, keyed as a handler would `set` them
     */
    public function __construct(
        private readonly string $key = 'demo-key',
        array $initialState = [],
        ?Serde $serde = null,
    ) {
        $this->serde = $serde ?? new JsonSerde();
        foreach ($initialState as $name => $value) {
            $this->state[$name] = $this->serde->serialize($value);
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function get(string $key): mixed
    {
        if (!\array_key_exists($key, $this->state)) {
            return null;
        }

        return $this->serde->deserialize($this->state[$key]);
    }

    /**
     * @return list<string>
     */
    public function stateKeys(): array
    {
        return \array_keys($this->state);
    }

    public function set(string $key, mixed $value): void
    {
        $this->state[$key] = $this->serde->serialize($value);
        ++$this->writes;
    }

    public function clear(string $key): void
    {
        unset($this->state[$key]);
        ++$this->writes;
    }

    public function clearAll(): void
    {
        $this->state = [];
        ++$this->writes;
    }

    /** How many state-mutating calls (set/clear/clearAll) the handler made. */
    public function writeCount(): int
    {
        return $this->writes;
    }

    /** Whether a state key is currently present. */
    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->state);
    }

    /**
     * The current state as a decoded map — exactly what a fresh handler would read back.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $decoded = [];
        foreach ($this->state as $name => $value) {
            $decoded[$name] = $this->serde->deserialize($value);
        }

        return $decoded;
    }
}
