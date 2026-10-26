<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Transport;

final class ChatResponse
{
    /**
     * @param  list<ToolCall>  $toolCalls
     */
    public function __construct(
        public readonly string $content,
        public readonly array $toolCalls = [],
        public readonly Usage $usage = new Usage,
        public readonly ?string $finishReason = null,
        public readonly string $raw = '',
    ) {}
}
