<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Transport;

final class Usage
{
    public function __construct(
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
    ) {}

    public function total(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function plus(self $other): self
    {
        return new self($this->promptTokens + $other->promptTokens, $this->completionTokens + $other->completionTokens);
    }

    /**
     * @param  array<mixed>|null  $usage  the `usage` object of an OpenAI-compatible response
     */
    public static function fromArray(?array $usage): self
    {
        $prompt = $usage['prompt_tokens'] ?? 0;
        $completion = $usage['completion_tokens'] ?? 0;

        return new self(is_numeric($prompt) ? (int) $prompt : 0, is_numeric($completion) ? (int) $completion : 0);
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    public function toArray(): array
    {
        return ['prompt_tokens' => $this->promptTokens, 'completion_tokens' => $this->completionTokens, 'total_tokens' => $this->total()];
    }
}
