# The `llm.run` process-engine effect

With [dskripchenko/process-engine](https://github.com/dskripchenko/process-engine)
(and its Laravel bridge, `dskripchenko/laravel-process-engine`), a process
can call a model as a durable effect. The integration lives in
`Dskripchenko\Llm\ProcessEngine` and is only loaded when you use it; the
engine is a suggested dependency.

```bash
composer require dskripchenko/laravel-process-engine
```

## Registration

None beyond using the class: an effect is a class the engine resolves from
the container. `RunLlm` (constants `EFFECT = 'llm.run'`, `DONE = 'llm.done'`,
`FAILED = 'llm.failed'`) declares
`#[Idempotent(retry: new Retry(attempts: 3, initialInterval: '30 seconds', backoff: 4.0, maxInterval: '15 minutes', attemptTimeout: '10 minutes'))]`.

## Use it as an action

```php
use Dskripchenko\Llm\Mode;
use Dskripchenko\Llm\ProcessEngine\LlmRun;
use Dskripchenko\Llm\ProcessEngine\RunLlm;

final class IssueTriage extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('new', fn (Builder $s) => $s
            ->on('issue.created', to: 'classifying', run: $this->prepare(...)));

        $p->state('classifying', fn (Builder $s) => $s
            ->entry(RunLlm::class)                                   // reads $context['llm']
            ->on(RunLlm::DONE, to: 'suggested', run: $this->keep(...))
            ->on(RunLlm::FAILED, to: 'manual'));
        // …
    }

    private function prepare(array &$context, array $event): void
    {
        $context['llm'] = LlmRun::prompt('issue.classify', ['body' => $event['description']])
            ->about('issue', $event['issueId'])
            ->mode(Mode::Suggest)
            ->tag('triage')
            ->toArray();
    }

    private function keep(array &$context, array $event): void
    {
        $context['suggestion'] = $event['data'];   // the validated structured answer
    }
}
```

The request comes from the triggering event's payload when it has `prompt` or
`user` — only a transition's `run:` sees the event:

```php
->on('summarize.requested', to: 'summarizing', run: RunLlm::class)
// payload: LlmRun::ask(Purpose::Summarize, 'Summarize the discussion.', $text)->toArray()
```

otherwise from `$context['llm']` (an `entry()` action has no event).

Use it as an action (`run:`, `entry()`), not with `invoke()`: the outcome
arrives as the `llm.done` / `llm.failed` events. (With `invoke()` the events
are still sent, but `done.invoke.*` fires as well.)

## `LlmRun` — the request as data

| | |
|---|---|
| `LlmRun::prompt($key, $variables)` | a preset |
| `LlmRun::ask(Purpose, $user, $data = null, $system = null)` | by hand; `$data` becomes a data block |
| `->scenario()`, `->about($type, $id)`, `->mode(Mode)`, `->tag()`, `->options([...])` | |
| `->toArray()` / `LlmRun::fromArray()` | |

## The events

`llm.done`: `text`, `data`, `tool_calls`, `usage`, `provider`, `model`,
`call_id`, `attempts`, `finish_reason`, `replayed`, `mode`, `tag`, `key`.

`llm.failed`: `code`, `message`, `details`, `mode`, `tag`, `key`. Codes:
`unavailable`, `rejected`, `invalid_output`, `quota_exceeded`,
`cloud_not_enabled`, `no_route`, `invalid_prompt`, `invalid_request`.

## Retries and idempotency

- The idempotency key is the engine's effect key — instance + step + action
  (`{instance}/step{n}/{action}#{i}`). The handler passes it to the call; a
  successful call logged under the same key is **replayed** from the log
  (`replayed: true`) instead of calling the model again, e.g. when a worker
  died after the model answered but before the event was stored.
- Transient failures (every provider unavailable) are thrown, and the engine
  retries under the policy. On the last attempt (`llm.effect.attempts`, keep
  it equal to the policy's attempts) `llm.failed` with code `unavailable` is
  sent instead.
- Every other failure is sent as `llm.failed` at once — they would not pass
  on a retry.
- Other exceptions (a database outage) propagate to the engine as ambiguous
  failures and are retried.

## Human in the loop: `Mode`

`Dskripchenko\Llm\Mode` is data the process carries with the request and
receives back in `llm.done.mode`. The package does nothing else with it; the
host process and UI implement the flow.

| Mode | Intended flow |
|---|---|
| `apply` | The process applies the answer at once (`on(llm.done)` writes it). For low-risk, reversible, visible changes; record them in history so a person can undo. |
| `suggest` (default) | The process stores the answer as a suggestion next to the subject and moves on; a person accepts, edits or dismisses it whenever they like. Nothing changes without them. |
| `approve` | The process waits in a state (`on('suggestion.approved')`, `on('suggestion.rejected')`, maybe `after('3 days')`) until a person decides; only an approval applies the answer. |

`Mode::appliesAutomatically()` and `Mode::waitsForHuman()` help branch on it.
A model's answer is untrusted input in every mode — validate it as you would
a person's.
