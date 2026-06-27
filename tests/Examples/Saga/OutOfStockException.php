<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Examples\Saga;

use RuntimeException;

/**
 * Raised by {@see InMemoryInventoryService::reserve} when stock is insufficient.
 *
 * It is a plain domain exception, deliberately NOT a Restate `TerminalException`: the
 * collaborator knows nothing about durable execution. Translating a domain failure
 * into the saga's terminal-rollback decision is the workflow's job, which keeps the
 * business logic reusable outside Restate.
 */
final class OutOfStockException extends RuntimeException
{
}
