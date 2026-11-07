<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Fixtures;

use Dskripchenko\Llm\Prompts\Prompt;
use Dskripchenko\Llm\Purpose;

final class SummarizeArticle extends Prompt
{
    public function key(): string
    {
        return 'article.summary';
    }

    public function purpose(): Purpose
    {
        return Purpose::Summarize;
    }

    public function system(): string
    {
        return 'You summarize articles of knowledge base {{ base }} in at most {{ words }} words.';
    }

    public function user(): string
    {
        return "Title: {{ title }}\nTags:\n{{ tags }}\n\n{{ body }}";
    }

    public function variables(): array
    {
        return ['base' => 'string', 'words' => 'int', 'title' => 'string', 'tags' => '?list', 'body' => 'data'];
    }

    public function options(): array
    {
        return ['temperature' => 0.2];
    }
}
