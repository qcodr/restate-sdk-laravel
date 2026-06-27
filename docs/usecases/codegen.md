# Typed client codegen

Calling another Restate service from a handler is stringly-typed by default:

```php
$greeting = $ctx->serviceCall('Greeter', 'greet', 'world');
```

The service name, handler name, argument shape, and return type are all just strings and
`mixed` — your IDE cannot autocomplete them and a typo only surfaces at runtime.
`php artisan restate:codegen` removes that gap: it generates a typed `{ServiceName}Client`
for every bound service, so the same call becomes autocompletable and type-checked:

```php
use App\Restate\Clients\GreeterClient;

$greeting = GreeterClient::fromContext($ctx)->greet('world'); // string, fully typed
```

## Running it

```bash
php artisan restate:codegen
```

For each class returned by `RestateManager::serviceClasses()` (your explicit `services` plus
any auto-discovered ones), the command writes one client file and prints its path:

```
Generating Restate clients in /app/app/Restate/Clients (namespace App\Restate\Clients):
  • /app/app/Restate/Clients/GreeterClient.php
```

By default files land in **`app/Restate/Clients`** under the **`App\Restate\Clients`**
namespace. Re-run the command after changing a service's handlers to regenerate; the files
are marked "do not edit by hand".

### Options

| Option        | Purpose                                  | Default                |
| ------------- | ---------------------------------------- | ---------------------- |
| `--output`    | Directory the client files are written to | `app/Restate/Clients`  |
| `--namespace` | PHP namespace for the generated clients   | `App\Restate\Clients`  |

```bash
php artisan restate:codegen --output=app/Generated/Restate --namespace='App\Generated\Restate'
```

A relative `--output` is anchored to the application base path; an absolute path is used
as-is. You can also set persistent defaults in your published `config/restate.php`:

```php
// config/restate.php
'codegen' => [
    'output' => app_path('Restate/Clients'),
    'namespace' => 'App\\Restate\\Clients',
],
```

Precedence is **option → config → built-in default**.

## The generated client shape

The client never instantiates the service (it reflects attributes only), so services with
constructor dependencies generate cleanly. Each handler produces three methods — an awaited
call, an `…Async` variant returning a `DurableFuture` for concurrent composition, and a
one-way `…Send` (fire-and-forget). For a `#[Service]` like `GreeterService`:

```php
<?php

declare(strict_types=1);

namespace App\Restate\Clients;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Context\DurableFuture;

/**
 * Typed Restate client for the "GreeterService" service.
 *
 * Generated from App\Restate\GreeterService by Qcodr\Restate\Sdk\Codegen\ClientGenerator;
 * do not edit by hand — re-run restate-codegen to regenerate.
 */
final class GreeterServiceClient
{
    private function __construct(
        private readonly Context $ctx,
    ) {
    }

    public static function fromContext(Context $ctx): self
    {
        return new self($ctx);
    }

    public function greet(string $name, ?string $idempotencyKey = null, array $headers = []): string
    {
        /** @var string $result */
        $result = $this->ctx->serviceCall('GreeterService', 'greet', $name, $idempotencyKey, $headers);

        return $result;
    }

    public function greetAsync(string $name, ?string $idempotencyKey = null, array $headers = []): DurableFuture
    {
        return $this->ctx->serviceCallAsync('GreeterService', 'greet', $name, $idempotencyKey, $headers);
    }

    public function greetSend(string $name, float $delaySeconds = 0.0, ?string $idempotencyKey = null, array $headers = []): void
    {
        $this->ctx->serviceSend('GreeterService', 'greet', $name, $delaySeconds, $idempotencyKey, $headers);
    }
}
```

Use it from any handler:

```php
use App\Restate\Clients\GreeterClient;

#[Handler]
public function welcome(Context $ctx, string $name): string
{
    $client = GreeterClient::fromContext($ctx);

    $hello = $client->greet($name);                 // awaited, typed result
    $future = $client->greetAsync($name);           // DurableFuture, compose concurrently
    $client->greetSend($name, delaySeconds: 5.0);   // fire-and-forget, optional delay

    return $hello;
}
```

### Keyed services

For a `#[VirtualObject]` or `#[Workflow]`, `fromContext` also takes the key, and the calls
delegate to `objectCall`/`objectSend` (or `workflowCall`/`workflowSend`):

```php
$count = CounterClient::fromContext($ctx, 'user-42')->increment();
```

## How it works

The command resolves `RestateManager` from the container to read the configured service
classes, then delegates each one to `Qcodr\Restate\Laravel\Codegen\ClientWriter`, a thin
adapter that calls the SDK's own `Qcodr\Restate\Sdk\Codegen\ClientGenerator` and writes the
result to disk. The Laravel package adds no generation logic of its own — it wires the SDK
generator into Artisan, the container, and your app's namespace. A service class that is not
autoloadable, or that lacks a `#[Service]`/`#[VirtualObject]`/`#[Workflow]` attribute, is
reported individually and the command exits non-zero, while the remaining valid services
still generate.
