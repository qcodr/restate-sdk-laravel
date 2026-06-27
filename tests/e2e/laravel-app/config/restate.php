<?php

declare(strict_types=1);

/*
 * End-to-end harness config for the Restate Laravel package.
 *
 * The three test services are listed explicitly and served over bidirectional HTTP/2 via
 * `php artisan restate:serve`. The in-app request/response route is disabled (`path => null`)
 * so the deployment is registered against the amphp host on port 9080.
 */

return [
    // The Service / Virtual Object / Workflow classes exposed by this deployment, each
    // resolved from the Laravel container (constructor DI honoured).
    'services' => [
        App\Restate\GreeterService::class,
        App\Restate\CounterObject::class,
        App\Restate\EchoWorkflow::class,
    ],

    // Directory auto-discovery is off here; the list above is explicit.
    'discover' => null,
    'discover_namespace' => 'App\\Restate',

    // Disable the in-app HTTP route: this harness serves over `restate:serve` (bidi HTTP/2),
    // and registers the deployment against that amphp host instead.
    'path' => null,
    'middleware' => ['api'],

    // No request-identity verification in the harness.
    'identity_key' => null,

    // The caller side: where Qcodr\Restate\Laravel\Client\RestateClient sends invocations
    // started from ordinary Laravel code. Inside the compose network the ingress is the
    // `restate` service on port 8080.
    'ingress' => [
        'url' => env('RESTATE_INGRESS_URL', 'http://restate:8080'),
        'token' => env('RESTATE_INGRESS_TOKEN'),
    ],

    // Bind settings for `php artisan restate:serve` (the bidirectional HTTP/2 host).
    'server' => [
        'host' => env('RESTATE_HOST', '0.0.0.0'),
        'port' => (int) env('RESTATE_PORT', 9080),
        'workers' => (int) env('RESTATE_WORKERS', 1),
    ],

    // Handler logs flow into Laravel's logging stack; null uses the default channel.
    'logging' => [
        'channel' => env('RESTATE_LOG_CHANNEL'),
    ],

    'codegen' => [
        'output' => null,
        'namespace' => null,
    ],

    'auth' => [
        'user_header' => 'x-restate-user',
        'tenant_header' => 'x-restate-tenant',
        'guard' => null,
        'tenant_context_key' => 'restate.tenant',
        'tenant_resolver' => null,
    ],
];
