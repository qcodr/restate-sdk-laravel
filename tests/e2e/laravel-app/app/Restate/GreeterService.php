<?php

declare(strict_types=1);

namespace App\Restate;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

/**
 * A stateless Restate Service exposed as an ordinary Laravel class.
 *
 * The e2e harness binds this through the Laravel container (constructor DI would be honoured
 * if it had any), serves it over bidirectional HTTP/2 via `php artisan restate:serve`, and
 * drives it through the Restate ingress.
 *
 * Note the input parameter is `?array`, not a typed value object: the SDK's JsonSerde hands
 * the handler the *decoded* JSON body (an associative array), so the name is read out of the
 * array at the boundary rather than from a hydrated object.
 */
#[Service]
final class GreeterService
{
    /**
     * @param array<string, mixed>|null $input decoded JSON body: `{"name": "<string>"}`
     */
    #[Handler]
    public function greet(Context $ctx, ?array $input = null): string
    {
        $name = \is_array($input) ? ($input['name'] ?? null) : null;
        if (!\is_string($name) || $name === '') {
            throw new TerminalException("The 'greet' handler requires a non-empty string 'name'.", 400);
        }

        return "Hello {$name}";
    }
}
