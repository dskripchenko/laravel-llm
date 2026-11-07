<?php

declare(strict_types=1);

namespace Dskripchenko\Llm;

/**
 * How a model's answer reaches the data it is about (human in the loop).
 *
 * - `Apply`: the process applies the answer at once (low-risk, reversible
 *   changes such as a tag suggestion that is also shown in history).
 * - `Suggest`: the answer is stored as a suggestion next to the subject;
 *   a person accepts, edits or dismisses it whenever they like, the
 *   process does not wait.
 * - `Approve`: the process waits in a state until a person approves or
 *   rejects the answer; only an approval applies it.
 *
 * The package carries the mode through a call (and the `llm.done` payload)
 * and nothing more; the host's process and UI implement the flow.
 */
enum Mode: string
{
    case Apply = 'apply';
    case Suggest = 'suggest';
    case Approve = 'approve';

    /** The answer changes data without a person looking at it first. */
    public function appliesAutomatically(): bool
    {
        return $this === self::Apply;
    }

    /** The process has to wait for a person's decision. */
    public function waitsForHuman(): bool
    {
        return $this === self::Approve;
    }
}
