# Generators & service auto-discovery

Two conveniences remove the boilerplate of standing up Restate handlers in a Laravel app:
`make:restate-*` generators that scaffold a correct, strict handler class, and a scanner
that finds attributed classes on disk so you do not hand-list every service in config.

## Scaffolding a handler

Each generator writes a `declare(strict_types=1)` `final` class into your app's `App\Restate`
namespace (`app/Restate/`), pre-wired with the right SDK attribute and a sample handler:

```bash
php artisan make:restate-service Greeter     # #[Service]        -> app/Restate/Greeter.php
php artisan make:restate-object  Counter     # #[VirtualObject]  -> app/Restate/Counter.php
php artisan make:restate-workflow OrderSaga  # #[Workflow]       -> app/Restate/OrderSaga.php
```

A generated service looks like this:

```php
<?php

declare(strict_types=1);

namespace App\Restate;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

#[Service]
final class Greeter
{
    /**
     * @param array<array-key, mixed>|null $input the decoded JSON request body
     *
     * @return array<string, mixed>
     */
    #[Handler]
    public function greet(Context $ctx, ?array $input = null): array
    {
        // validate + build your input here
        return ['message' => 'Hello from Greeter'];
    }
}
```

### Why the handler takes `?array $input`

The SDK's `JsonSerde` coerces only **scalar** type hints. For a JSON object body it hands the
handler the decoded associative **array** as-is — it does not hydrate custom classes. A handler
declaring a typed value object (`Order $order`) would therefore receive an `array` at runtime and
fail with a `TypeError` on every (retryable) attempt, looping forever. The stubs accept the raw
`?array` and leave a `// validate + build your input here` marker so you validate and build your
own input object at the boundary. The object and workflow stubs follow the same rule and additionally
demonstrate per-key state (`ObjectContext::set`) and a durable step (`WorkflowContext::run`).

## Auto-discovery

Instead of listing every class under `services`, point the `discover` key at the directory the
generators write to:

```php
// config/restate.php
return [
    // Explicit bindings still work and are merged with discovered ones:
    'services' => [
        // App\Restate\GreeterService::class,
    ],

    // Auto-register every #[Service] / #[VirtualObject] / #[Workflow] class found here:
    'discover' => app_path('Restate'),
];
```

At boot, `RestateManager` feeds this directory and its PSR-4 namespace to
`Qcodr\Restate\Laravel\Discovery\ServiceScanner::scan()`, which returns the fully-qualified names of
every class carrying a `#[Service]`, `#[VirtualObject]`, or `#[Workflow]` attribute — sorted, so the
discovery order (and thus the manifest) is deterministic across machines and deploys. Plain,
non-attributed classes in the directory are ignored, as are abstract classes. The discovered classes
are bound exactly like hand-listed ones: each is resolved through the Laravel container, so handlers
still receive constructor dependency injection.

> Wiring note: `ServiceScanner` is the building block; `RestateManager::serviceClasses()` is what
> merges the discovered FQCNs with the explicit `services` list. The scanner only resolves a file to a
> class through the autoloader (it never parses or executes file contents beyond that), and only
> returns classes that actually exist at their PSR-4 path — a misplaced file is skipped, not fatal.

## Verifying

`php artisan restate:discover` lists every bound service (explicit + discovered), and
`php artisan restate:discover --json` prints the raw manifest the Restate runtime fetches — a quick
way to confirm a freshly generated handler is registered without a live runtime.
