<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Fixtures;

use Dskripchenko\Llm\Mode;
use Dskripchenko\Llm\ProcessEngine\LlmRun;
use Dskripchenko\Llm\ProcessEngine\RunLlm;
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Process;

final class TriageProcess extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('new', fn (Builder $s) => $s
            ->on('classify', to: 'classifying', run: $this->prepare(...))
            ->on('ask', to: 'asking', run: RunLlm::class));   // the request is the event's payload

        $p->state('classifying', fn (Builder $s) => $s
            ->entry(RunLlm::class)
            ->on(RunLlm::DONE, to: 'review', run: $this->keepSuggestion(...))
            ->on(RunLlm::FAILED, to: 'manual', run: $this->keepError(...)));

        $p->state('asking', fn (Builder $s) => $s
            ->on(RunLlm::DONE, to: 'answered', run: $this->keepAnswer(...))
            ->on(RunLlm::FAILED, to: 'manual', run: $this->keepError(...)));

        $p->final('review');
        $p->final('answered');
        $p->final('manual');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function prepare(array &$context): void
    {
        $context['llm'] = LlmRun::prompt('issue.classify', ['body' => $context['body']])
            ->about('issue', (int) $context['issueId'])
            ->mode(Mode::Suggest)
            ->tag('triage')
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $event
     */
    private function keepSuggestion(array &$context, array $event): void
    {
        $context['suggestion'] = $event['data'];
        $context['mode'] = $event['mode'];
        $context['tag'] = $event['tag'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $event
     */
    private function keepAnswer(array &$context, array $event): void
    {
        $context['answer'] = $event['text'];
        $context['mode'] = $event['mode'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $event
     */
    private function keepError(array &$context, array $event): void
    {
        $context['error'] = $event['code'];
    }
}
