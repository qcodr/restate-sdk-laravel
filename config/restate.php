<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    |
    | The Restate service / virtual-object / workflow classes exposed by this
    | deployment. List their class names; each is resolved from the Laravel
    | container, so handlers receive constructor dependency injection. The
    | classes use the SDK attributes (#[Service], #[VirtualObject], #[Workflow]).
    |
    */
    'services' => [
        // App\Restate\GreeterService::class,

        // Uncomment to run Laravel jobs dispatched on the `restate` queue connection:
        // \Qcodr\Restate\Laravel\Queue\JobRunner::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-discovery
    |--------------------------------------------------------------------------
    |
    | Optionally scan a directory for #[Service]/#[VirtualObject]/#[Workflow]
    | classes and register them automatically (merged with `services` above).
    | `discover` is the directory; `discover_namespace` is the namespace it maps
    | to. Null disables scanning.
    |
    |   'discover' => app_path('Restate'),
    |
    */
    'discover' => env('RESTATE_DISCOVER'),
    'discover_namespace' => 'App\\Restate',

    /*
    |--------------------------------------------------------------------------
    | HTTP route
    |--------------------------------------------------------------------------
    |
    | The route prefix the Restate runtime calls for discovery and invocation.
    | Register the deployment at <app-url>/<path>. Set `path` to null to disable
    | the in-app HTTP route entirely (e.g. when serving via `restate:serve`).
    |
    */
    'path' => env('RESTATE_PATH', 'restate'),
    'middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Request identity
    |--------------------------------------------------------------------------
    |
    | Opt-in request-identity verification (requires ext-sodium). Set your
    | Restate environment's public key (publickeyv1_...) to reject requests not
    | signed by your runtime. Null disables verification.
    |
    */
    'identity_key' => env('RESTATE_IDENTITY_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Ingress (dispatching invocations from Laravel)
    |--------------------------------------------------------------------------
    |
    | Where the client side (Qcodr\Restate\Laravel\Client\RestateClient, exposed as
    | Restate::client()) sends invocations started from ordinary Laravel code —
    | controllers, jobs, listeners. `url` is the Restate ingress base URL (port 8080
    | by default); `token`, when set, is sent as `Authorization: Bearer <token>` for
    | a secured ingress (e.g. Restate Cloud). This is the *caller*, distinct from the
    | `path`/`server` settings above, which serve *this* app's handlers to the runtime.
    |
    */
    'ingress' => [
        'url' => env('RESTATE_INGRESS_URL', 'http://localhost:8080'),
        'token' => env('RESTATE_INGRESS_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Standalone server (`php artisan restate:serve`)
    |--------------------------------------------------------------------------
    |
    | Settings for the bidirectional HTTP/2 (amphp) server, an alternative to the
    | in-app HTTP route. `workers` > 1 pre-forks that many processes (needs
    | ext-pcntl); 0 auto-detects the CPU count.
    |
    */
    'server' => [
        'host' => env('RESTATE_HOST', '0.0.0.0'),
        'port' => (int) env('RESTATE_PORT', 9080),
        'workers' => (int) env('RESTATE_WORKERS', 1),
    ],
];
