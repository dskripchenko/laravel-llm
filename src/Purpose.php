<?php

declare(strict_types=1);

namespace Dskripchenko\Llm;

/**
 * What a call is for. Callers ask for a purpose; a route decides which
 * provider and model serve it.
 */
enum Purpose: string
{
    case Chat = 'chat';
    case Summarize = 'summarize';
    case Classify = 'classify';
    case Extract = 'extract';
    case Embed = 'embed';
    case Rerank = 'rerank';

    /** Purposes served by `/chat/completions`. */
    public function isGenerative(): bool
    {
        return ! in_array($this, [self::Embed, self::Rerank], true);
    }
}
