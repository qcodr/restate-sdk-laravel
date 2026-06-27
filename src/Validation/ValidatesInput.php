<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Validation;

/**
 * Validates and shapes a Restate handler's decoded-array input at the system boundary.
 *
 * WHY this exists: the SDK's {@see \Qcodr\Restate\Sdk\Serde\JsonSerde} does not hydrate
 * custom classes — a JSON object body reaches a handler as a decoded associative array
 * (or `null` for an empty body), so every handler must validate and shape that array
 * itself before the durable logic runs. This trait wraps Laravel's `Validator` so a
 * handler does that in one line instead of hand-rolling the per-field checks (see the
 * `Order::fromArray` pattern in `tests/Examples/Saga/Order.php`, which this replaces).
 *
 * WHY a failure is terminal: a Restate handler is invoked with the SAME journaled input
 * on every retry, so input that fails validation will fail identically forever. Retrying
 * is pointless and wasteful, so a validation failure is a permanent client error
 * (HTTP 400) surfaced as a {@see RestateValidationException} — a terminal exception the
 * runtime returns to the caller without retrying — never a transient, retryable error.
 */
trait ValidatesInput
{
    /**
     * Validates the decoded handler input against Laravel rules and returns the validated
     * subset: every key NOT covered by a rule is stripped, so the durable logic only ever
     * sees data it explicitly asked for.
     *
     * @param array<string, mixed>|null $input      the decoded JSON body; `null` for an empty body
     * @param array<string, mixed>      $rules       Laravel validation rules, keyed by field name
     * @param array<string, string>     $messages    optional custom error messages
     * @param array<string, string>     $attributes  optional human-readable attribute names
     *
     * @return array<string, mixed> the validated subset of the input
     *
     * @throws RestateValidationException when validation fails (terminal HTTP 400)
     */
    protected function validateInput(
        ?array $input,
        array $rules,
        array $messages = [],
        array $attributes = [],
    ): array {
        // A null body (empty request) validates as an empty array, so `required` rules
        // fail cleanly instead of dereferencing null.
        $validator = validator($input ?? [], $rules, $messages, $attributes);

        if ($validator->fails()) {
            throw RestateValidationException::fromMessageBag($validator->errors());
        }

        // `safe(array_keys($rules))` is the validated data restricted to the declared
        // fields. It is preferred over `validated()` because Larastan types it as
        // `array<string, mixed>` (whereas `validated()` is an untyped `array`); for the
        // flat and dotted rules used at a handler boundary the two are equivalent.
        return $validator->safe(\array_keys($rules));
    }
}
