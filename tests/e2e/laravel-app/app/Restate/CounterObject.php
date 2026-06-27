<?php

declare(strict_types=1);

namespace App\Restate;

use Qcodr\Restate\Sdk\Context\ObjectContext;
use Qcodr\Restate\Sdk\Context\SharedObjectContext;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Shared;
use Qcodr\Restate\Sdk\Service\Attribute\VirtualObject;

/**
 * A keyed counter Virtual Object: per-key durable state with a single exclusive writer.
 *
 * Restate serialises exclusive (`#[Handler]`) invocations per key, so the read-modify-write
 * in {@see add} is race-free with no `lockForUpdate`. Each object key (`acme`, `globex`, …)
 * keeps fully independent state. The e2e drives `add` twice on one key and asserts the total
 * increments (1 then 2), then reads it back via the concurrent `#[Shared] get`.
 *
 * Input is `?array` (the decoded JSON body) per the SDK's JsonSerde contract.
 */
#[VirtualObject]
final class CounterObject
{
    /** State key under which the running total is journaled, per object key. */
    private const COUNT = 'count';

    /**
     * Exclusive (single-writer) handler: add `by` (default 1) to this key's total and return
     * the new total. The get -> compute -> set is atomic with respect to every other `add` on
     * the same key.
     *
     * @param array<string, mixed>|null $input decoded JSON body: `{"by"?: <int>=1}`
     */
    #[Handler]
    public function add(ObjectContext $ctx, ?array $input = null): int
    {
        $by = \is_array($input) ? ($input['by'] ?? 1) : 1;
        if (!\is_int($by)) {
            throw new TerminalException("The 'add' handler 'by' must be an integer.", 400);
        }

        $current = $ctx->get(self::COUNT);
        $next = (\is_int($current) ? $current : 0) + $by;
        $ctx->set(self::COUNT, $next);

        return $next;
    }

    /**
     * Shared (read-only, concurrent) handler: report the current total without mutating state.
     * Writing here would not even type-check, since the context is a {@see SharedObjectContext}.
     */
    #[Shared]
    public function get(SharedObjectContext $ctx): int
    {
        $current = $ctx->get(self::COUNT);

        return \is_int($current) ? $current : 0;
    }
}
