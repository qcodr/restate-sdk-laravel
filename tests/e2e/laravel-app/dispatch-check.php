<?php

declare(strict_types=1);

/*
 * Caller-side proof: boot the real Laravel app, resolve the package's RestateClient from the
 * container exactly as application code would, and start an invocation through the Restate
 * ingress. Asserts the handler's result comes back, proving the dispatch path
 * (Laravel code -> RestateClient -> ingress -> runtime -> handler -> result) end to end.
 *
 * Run inside the app container: `php dispatch-check.php`
 * Exit 0 on success, 1 on failure (so the e2e runner can assert on it).
 */

use Illuminate\Contracts\Console\Kernel;
use Qcodr\Restate\Laravel\Client\RestateClient;

require __DIR__ . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__ . '/bootstrap/app.php';

// Bootstrap the console kernel so config, env, and providers (incl. the Restate provider and
// its singletons) are loaded — the same wiring an Artisan command or HTTP request would get.
$app->make(Kernel::class)->bootstrap();

$client = $app->make(RestateClient::class);

$result = $client->call('GreeterService', 'greet', ['name' => 'ada']);

if ($result !== 'Hello ada') {
    \fwrite(\STDERR, 'dispatch-check FAIL: expected "Hello ada", got ' . \json_encode($result) . "\n");
    exit(1);
}

\fwrite(\STDOUT, 'dispatch-check OK: RestateClient->call returned "' . $result . "\"\n");
exit(0);
