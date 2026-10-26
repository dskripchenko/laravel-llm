<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Fixtures;

use Dskripchenko\Llm\Prompts\Prompt;
use Dskripchenko\Llm\Purpose;

final class SummarizeIssue extends Prompt
{
    public function key(): string
    {
        return 'issue.summary';
    }

    public function purpose(): Purpose
    {
        return Purpose::Summarize;
    }

    public function system(): string
    {
        return 'You summarize issues of project {{ project }} in at most {{ words }} words.';
    }

    public function user(): string
    {
        return "Title: {{ title }}\nLabels:\n{{ labels }}\n\n{{ body }}";
    }

    public function variables(): array
    {
        return ['project' => 'string', 'words' => 'int', 'title' => 'string', 'labels' => '?list', 'body' => 'data'];
    }

    public function options(): array
    {
        return ['temperature' => 0.2];
    }
}
