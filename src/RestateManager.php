<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel;

use Illuminate\Contracts\Container\Container;
use Qcodr\Restate\Laravel\Client\RestateClient;
use Qcodr\Restate\Laravel\Discovery\ServiceScanner;
use Qcodr\Restate\Laravel\Logging\RestateLogger;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\ProtocolMode;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;

/**
 * Builds the Restate {@see Endpoint} from the package configuration and the Laravel
 * container: each configured service class is resolved through the container, so
 * handlers receive constructor dependency injection like any other Laravel class.
 *
 * The endpoint is memoised — service instances are stateless (per-invocation data lives
 * in local variables or Restate state), so one instance is shared across requests, the
 * same contract the SDK requires of bound services.
 */
final class RestateManager
{
    /**
     * Default Restate ingress base URL — a local runtime's HTTP ingress on its standard port.
     */
    private const DEFAULT_INGRESS_URL = 'http://localhost:8080';

    private ?Endpoint $endpoint = null;

    /**
     * @param array<array-key, mixed> $config the `restate` config array
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $config,
    ) {
    }

    /**
     * The configured service class names.
     *
     * @return list<string>
     */
    public function serviceClasses(): array
    {
        $services = $this->config['services'] ?? [];
        $explicit = \is_array($services) ? \array_values(\array_filter(
            $services,
            static fn (mixed $class): bool => \is_string($class) && $class !== '',
        )) : [];

        // Optional auto-discovery: scan a directory (config `discover`, mapped to the
        // `discover_namespace`, default App\Restate) for #[Service]/#[VirtualObject]/
        // #[Workflow] classes and merge them with the explicit list.
        $discover = $this->config['discover'] ?? null;
        if (!\is_string($discover) || $discover === '') {
            return $explicit;
        }

        $namespace = $this->config['discover_namespace'] ?? 'App\\Restate';
        $namespace = \is_string($namespace) && $namespace !== '' ? $namespace : 'App\\Restate';
        $discovered = (new ServiceScanner())->scan($discover, $namespace);

        return \array_values(\array_unique([...$explicit, ...$discovered]));
    }

    /**
     * Builds (once) the endpoint with every configured service bound and, when set,
     * request-identity verification enabled.
     */
    public function endpoint(): Endpoint
    {
        if ($this->endpoint !== null) {
            return $this->endpoint;
        }

        // Always build in BidiStream mode. The in-app request/response route caps discovery
        // back to REQUEST_RESPONSE (its RequestProcessor has no bidi capability), while
        // `restate:serve` (AmpStreamingServer) serves true bidi — one endpoint, correct on
        // both hosts.
        $builder = Endpoint::builder()->protocolMode(ProtocolMode::BidiStream);
        foreach ($this->serviceClasses() as $class) {
            $instance = $this->container->make($class);
            if (\is_object($instance)) {
                $builder->bind($instance);
            }
        }

        $identityKey = $this->config['identity_key'] ?? null;
        if (\is_string($identityKey) && $identityKey !== '') {
            $builder->identityKey($identityKey);
        }

        return $this->endpoint = $builder->build();
    }

    /**
     * A {@see RequestProcessor} over the configured endpoint — the bytes-in/bytes-out
     * core the HTTP route drives.
     */
    public function processor(): RequestProcessor
    {
        // Feed Laravel's logger (replay-aware via the SDK's ReplayAwareLogger) so handler
        // logs land in the app's channels exactly once.
        return new RequestProcessor(
            $this->endpoint(),
            logger: $this->container->make(RestateLogger::class),
        );
    }

    /**
     * The HTTP route prefix the runtime calls, or null when the in-app route is disabled.
     */
    public function routePath(): ?string
    {
        $path = $this->config['path'] ?? null;

        return \is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * The route middleware group.
     *
     * @return list<string>
     */
    public function routeMiddleware(): array
    {
        $middleware = $this->config['middleware'] ?? ['api'];
        if (!\is_array($middleware)) {
            return ['api'];
        }

        return \array_values(\array_filter($middleware, static fn (mixed $m): bool => \is_string($m)));
    }

    /**
     * The ingress dispatcher (client side) for starting invocations from Laravel code.
     * Resolved from the container so it stays a shared singleton; exposed here so the
     * `Restate` facade can reach it as `Restate::client()`.
     */
    public function client(): RestateClient
    {
        return $this->container->make(RestateClient::class);
    }

    /**
     * The Restate ingress connection settings the {@see RestateClient} is built from: the base
     * `url` (falling back to a local runtime) and an optional bearer `token` for a secured
     * ingress. Empty/non-string values are normalised away so the client always receives a
     * usable URL and a real-or-null token.
     *
     * @return array{url: string, token: string|null}
     */
    public function ingressConfig(): array
    {
        $ingress = $this->config['ingress'] ?? [];
        $ingress = \is_array($ingress) ? $ingress : [];

        $url = $ingress['url'] ?? self::DEFAULT_INGRESS_URL;
        $token = $ingress['token'] ?? null;

        return [
            'url' => \is_string($url) && $url !== '' ? $url : self::DEFAULT_INGRESS_URL,
            'token' => \is_string($token) && $token !== '' ? $token : null,
        ];
    }

    /**
     * Host / port / workers for the standalone `restate:serve` server.
     *
     * @return array{host: string, port: int, workers: int}
     */
    public function serverConfig(): array
    {
        $server = $this->config['server'] ?? [];
        $server = \is_array($server) ? $server : [];

        $host = $server['host'] ?? '0.0.0.0';
        $port = $server['port'] ?? 9080;
        $workers = $server['workers'] ?? 1;

        return [
            'host' => \is_string($host) ? $host : '0.0.0.0',
            'port' => \is_int($port) ? $port : (int) (\is_numeric($port) ? $port : 9080),
            'workers' => \is_int($workers) ? $workers : (int) (\is_numeric($workers) ? $workers : 1),
        ];
    }
}
