<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\ProcessEngine;

use Dskripchenko\Llm\Exceptions\LlmException;
use Dskripchenko\Llm\LlmManager;
use Dskripchenko\ProcessEngine\Attributes\Idempotent;
use Dskripchenko\ProcessEngine\Effect\Effect;
use Dskripchenko\ProcessEngine\Effect\Retry;
use Dskripchenko\ProcessEngine\Effect\Send;
use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

/**
 * The `llm.run` effect: calls a model for a process and sends the outcome
 * back to the instance as an event — `llm.done` with the result or
 * `llm.failed` with an error code.
 *
 * Use it as an action (`run:` of a transition or `entry()` of a state),
 * so that the outcome arrives as these events:
 *
 *     $p->state('review', fn (Builder $s) => $s
 *         ->entry(RunLlm::class)                      // reads $context['llm']
 *         ->on(RunLlm::DONE, to: 'review', run: $this->keepSuggestion(...))
 *         ->on(RunLlm::FAILED, to: 'manual'));
 *
 * The request is the triggering event's payload when it carries one
 * (`prompt` or `user`; only a transition's `run:` sees the event, `entry()`
 * does not), otherwise `$context['llm']` — see LlmRun.
 *
 * Idempotent by the engine's effect key (instance + step + action): a
 * retry after the model answered but before the event was recorded replays
 * the logged answer instead of calling the model again. Transient failures
 * (every provider down) are thrown for the engine to retry; on the last
 * attempt, and for every other failure (quota, schema, refusal,
 * configuration), `llm.failed` is sent instead.
 */
#[Idempotent(retry: new Retry(attempts: 3, initialInterval: '30 seconds', backoff: 4.0, maxInterval: '15 minutes', attemptTimeout: '10 minutes'))]
final class RunLlm
{
    public const EFFECT = 'llm.run';

    public const DONE = 'llm.done';

    public const FAILED = 'llm.failed';

    public function __construct(
        private readonly LlmManager $llm,
        private readonly Config $config,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function __invoke(Effect $fx, array $context = [], mixed $input = null): Send
    {
        $spec = null;
        try {
            $spec = LlmRun::fromArray(self::request($input, $context));
            $result = $spec->toPending($this->llm)->idempotencyKey($fx->key)->run();
        } catch (LlmException $e) {
            if ($e->isTransient() && $fx->attempt < $this->attempts()) {
                throw $e;
            }

            return Send::to($fx->instanceId, self::FAILED, [
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
                'details' => $e->details(),
            ] + self::echo($spec, $fx));
        } catch (InvalidArgumentException|\ValueError $e) {
            return Send::to($fx->instanceId, self::FAILED, [
                'code' => 'invalid_request',
                'message' => $e->getMessage(),
                'details' => [],
            ] + self::echo($spec, $fx));
        }

        return Send::to($fx->instanceId, self::DONE, $result->toArray() + ['replayed' => $result->replayed] + self::echo($spec, $fx));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<mixed>
     */
    private static function request(mixed $input, array $context): array
    {
        if (is_array($input) && (isset($input['prompt']) || isset($input['user']))) {
            return $input;
        }
        if (is_array($context['llm'] ?? null)) {
            return $context['llm'];
        }

        throw new InvalidArgumentException('llm.run found no request: pass it as the event payload or put it in $context[\'llm\']');
    }

    /**
     * @return array{mode: string|null, tag: string|null, key: string}
     */
    private static function echo(?LlmRun $spec, Effect $fx): array
    {
        return ['mode' => $spec?->mode->value, 'tag' => $spec?->tag, 'key' => $fx->key];
    }

    private function attempts(): int
    {
        $attempts = $this->config->get('llm.effect.attempts', 3);

        return is_numeric($attempts) ? (int) $attempts : 3;
    }
}
