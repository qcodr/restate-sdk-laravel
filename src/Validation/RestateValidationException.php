<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Validation;

use Illuminate\Support\MessageBag;
use Qcodr\Restate\Sdk\Error\TerminalException;

/**
 * A handler-input validation failure, surfaced to the Restate runtime as a terminal
 * (non-retryable) HTTP 400.
 *
 * It extends the SDK's {@see TerminalException} so the runtime journals the failure and
 * returns it to the caller WITHOUT retrying — the correct behaviour because the handler
 * is always replayed with the same input, so a validation failure can never succeed on a
 * later attempt. The per-field error messages travel back to the caller through the
 * inherited {@see TerminalException::$metadata} (service protocol V7), so the client sees
 * exactly which fields were wrong.
 */
final class RestateValidationException extends TerminalException
{
    /**
     * HTTP 400 Bad Request: the caller sent malformed input. Distinct from the SDK's
     * default 500 so a client error is never mistaken for a server fault.
     */
    public const STATUS_CODE = 400;

    /**
     * @param string                       $message human-readable summary of what failed
     * @param array<string, array<string>> $errors  field name => list of error messages
     */
    public function __construct(
        string $message,
        private readonly array $errors,
    ) {
        parent::__construct($message, self::STATUS_CODE, metadata: self::toMetadata($errors));
    }

    /**
     * Builds the exception from the Validator's {@see MessageBag} — the value Laravel
     * returns from `$validator->errors()`.
     */
    public static function fromMessageBag(MessageBag $errors): self
    {
        $messages = $errors->messages();

        return new self(self::summarize($messages), $messages);
    }

    /**
     * The validation errors keyed by field, for callers that want the structured form
     * rather than the flattened {@see TerminalException::$metadata} string map.
     *
     * @return array<string, array<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Flattens the per-field message lists into the `string => string` shape the terminal
     * failure metadata requires, joining a field's messages with "; ".
     *
     * @param array<string, array<string>> $errors
     *
     * @return array<string, string>
     */
    private static function toMetadata(array $errors): array
    {
        $metadata = [];
        foreach ($errors as $field => $messages) {
            $metadata[$field] = \implode('; ', $messages);
        }

        return $metadata;
    }

    /**
     * Joins every field's messages into one human-readable sentence for the exception
     * message, so logs and the caller's error body are useful without parsing metadata.
     *
     * @param array<string, array<string>> $errors
     */
    private static function summarize(array $errors): string
    {
        $parts = [];
        foreach ($errors as $messages) {
            foreach ($messages as $message) {
                $parts[] = $message;
            }
        }

        if ($parts === []) {
            return 'The given handler input is invalid.';
        }

        return 'The given handler input is invalid: ' . \implode(' ', $parts);
    }
}
