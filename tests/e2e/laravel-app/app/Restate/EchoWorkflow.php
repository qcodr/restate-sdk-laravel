<?php

declare(strict_types=1);

namespace App\Restate;

use Qcodr\Restate\Sdk\Context\WorkflowContext;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Workflow;

/**
 * A minimal durable Workflow: its `run` handler executes exactly once per workflow key.
 *
 * The body performs one durable side effect via `$ctx->run('echo-step', ...)` — the closure
 * runs once, its result is journaled, and on any retry the stored result is replayed instead
 * of re-running. The e2e invokes `run` on key `wf-1` and asserts the echoed value comes back.
 *
 * Input is `?array` (the decoded JSON body) per the SDK's JsonSerde contract.
 */
#[Workflow]
final class EchoWorkflow
{
    /**
     * @param array<string, mixed>|null $input decoded JSON body: `{"value": "<string>"}`
     *
     * @return array{echo: string, value: string} the echoed payload plus its run result
     */
    #[Handler]
    public function run(WorkflowContext $ctx, ?array $input = null): array
    {
        $value = \is_array($input) ? ($input['value'] ?? null) : null;
        if (!\is_string($value) || $value === '') {
            throw new TerminalException("The 'run' handler requires a non-empty string 'value'.", 400);
        }

        // Durable step: runs once, replays from the journal on retry. The step name doubles
        // as its journal key, so it must stay stable across deploys.
        $echo = $ctx->run('echo-step', static fn (): string => 'echo:' . $value);

        return ['echo' => \is_string($echo) ? $echo : 'echo:' . $value, 'value' => $value];
    }
}
