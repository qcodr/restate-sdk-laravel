# Handler-input validation: validate at the boundary, fail terminally

Restate handlers receive their request body as a **decoded associative array**, not a
hydrated object. The SDK's `JsonSerde` only coerces *scalar* type hints; for a JSON object
body it hands the handler the array as-is. So every handler signature looks like this:

```php
#[Handler]
public function create(Context $ctx, ?array $input = null): OrderResult
```

`?array` because an empty request body decodes to `null`. Before any durable step runs, the
handler must turn that raw array into trusted, well-shaped data — that is the job of
`ValidatesInput`.

> Source: `src/Validation/ValidatesInput.php` and
> `src/Validation/RestateValidationException.php`, with tests in
> `tests/Unit/Validation/`.

## The pattern

`use` the trait and validate the input in one line at the top of the handler:

```php
use Qcodr\Restate\Laravel\Validation\ValidatesInput;

#[Workflow]
final class OrderWorkflow
{
    use ValidatesInput;

    #[Handler]
    public function run(WorkflowContext $ctx, ?array $input = null): OrderResult
    {
        $data = $this->validateInput($input, [
            'orderId'  => 'required|string',
            'quantity' => 'required|integer|min:1',
        ]);

        // $data is the validated subset: ['orderId' => '…', 'quantity' => 3].
        // Any key the rules did not mention has been stripped.
        $reservationId = $ctx->run('reserve', fn () => $this->inventory->reserve(
            $data['orderId'],
            $data['quantity'],
        ));
        // …
    }
}
```

`validateInput(?array $input, array $rules, array $messages = [], array $attributes = [])`
runs Laravel's `Validator` on `$input ?? []` and:

- **on success** returns only the **validated subset** — keys without a rule are removed, so
  the durable logic can never act on a field it did not ask for (e.g. an injected
  `isAdmin` flag);
- **on failure** throws a `RestateValidationException`.

## Why a validation failure is terminal (HTTP 400)

A Restate handler is invoked with the **same journaled input on every retry**. Input that
fails validation will fail *identically* forever, so retrying is pointless and wasteful.
A validation failure is therefore a **permanent client error**, not a transient one.

`RestateValidationException` extends the SDK's `TerminalException` with status **400**, so
the runtime journals the failure and returns it to the caller **without retrying**. The
per-field messages ride back to the caller in the exception `metadata`:

```php
try {
    $data = $this->validateInput($input, $rules);
} catch (RestateValidationException $e) {
    $e->statusCode();   // 400
    $e->errors();       // ['orderId' => ['The order id field is required.'], …]
    $e->metadata;       // ['orderId' => 'The order id field is required.', …] (string => string)
    $e->getMessage();   // 'The given handler input is invalid: …'
}
```

You never need to catch it inside the handler — letting it propagate is exactly right: the
runtime turns it into a 400 for the caller.

## Empty body

An empty request body decodes to `null`. `validateInput(null, …)` validates against an
empty array, so `required` rules fail and the caller gets a terminal 400 — no
null-dereference, no special-casing in the handler.

## Contrast with the hand-rolled `fromArray` pattern

The saga example (`tests/Examples/Saga/Order.php`) validates by hand: a `fromArray` factory
with `requireString`/`requireInt` helpers, each throwing `new TerminalException(…, 400)`.
That is fine when you also want a typed value object out the other end, but it is a lot of
boilerplate that drifts per field.

| | `Order::fromArray($input)` | `$this->validateInput($input, $rules)` |
|---|---|---|
| Rules | Hand-written `requireX` helpers | Declarative Laravel rules |
| Unlisted keys | Ignored (not stripped) | **Stripped** from the result |
| Failure | `TerminalException` 400 | `RestateValidationException` 400 (a `TerminalException`) |
| Output | A typed `Order` value object | The validated `array` subset |
| Reuse | Per-class | The framework's full rule set (`email`, `in`, `between`, …) |

Use `validateInput` for the common case — validate and shape the request at the boundary.
Reach for a `fromArray` value object when downstream code genuinely benefits from a typed,
immutable object rather than an array.

## Test it without a runtime

The trait only needs the Laravel container, so it is unit-testable with Testbench and no
Restate runtime:

```bash
vendor/bin/phpunit --filter Validation
```

`tests/Unit/Validation/ValidatesInputTest.php` drives a fixture handler with valid input
(asserting the validated subset and that unlisted keys are stripped) and with missing,
non-integer, below-minimum, and empty-body input (asserting a terminal 400 whose `errors()`
and `metadata` name the offending fields).
