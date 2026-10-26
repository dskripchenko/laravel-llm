<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Fixtures;

use Dskripchenko\Llm\Prompts\Prompt;
use Dskripchenko\Llm\Purpose;

final class ClassifyIssue extends Prompt
{
    public function key(): string
    {
        return 'issue.classify';
    }

    public function purpose(): Purpose
    {
        return Purpose::Classify;
    }

    public function system(): string
    {
        return 'Classify the issue.';
    }

    public function user(): string
    {
        return '{{ body }}';
    }

    public function variables(): array
    {
        return ['body' => 'data'];
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['bug', 'feature', 'question']],
                'priority' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
            ],
            'required' => ['type', 'priority'],
            'additionalProperties' => false,
        ];
    }
}
