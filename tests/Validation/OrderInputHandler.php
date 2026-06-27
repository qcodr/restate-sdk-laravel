<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Validation;

use Qcodr\Restate\Laravel\Validation\ValidatesInput;

/**
 * A minimal fixture handler exercising {@see ValidatesInput} exactly as a real handler
 * would: it receives the raw decoded body (`?array`) the SDK serde hands it, validates it
 * at the boundary, and returns the validated subset for the durable logic to consume.
 */
final class OrderInputHandler
{
    use ValidatesInput;

    /**
     * @param array<string, mixed>|null $input the decoded JSON request body
     *
     * @return array<string, mixed> the validated order fields
     */
    public function create(?array $input): array
    {
        return $this->validateInput($input, [
            'orderId' => 'required|string',
            'quantity' => 'required|integer|min:1',
        ]);
    }
}
