<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Fixtures;

use Dskripchenko\Llm\Prompts\Prompt;
use Dskripchenko\Llm\Purpose;

final class ClassifyArticle extends Prompt
{
    public function key(): string
    {
        return 'article.classify';
    }

    public function purpose(): Purpose
    {
        return Purpose::Classify;
    }

    public function system(): string
    {
        return 'Classify the article.';
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
                'type' => ['type' => 'string', 'enum' => ['guide', 'reference', 'faq']],
                'level' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
            ],
            'required' => ['type', 'level'],
            'additionalProperties' => false,
        ];
    }
}
