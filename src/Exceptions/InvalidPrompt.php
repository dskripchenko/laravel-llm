<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Exceptions;

/**
 * A prompt cannot be built: unknown key, a missing or mistyped variable,
 * a placeholder the preset does not declare.
 */
final class InvalidPrompt extends LlmException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly string $key, public readonly array $problems)
    {
        parent::__construct("Prompt `{$key}`: ".implode('; ', $problems));
    }

    public function errorCode(): string
    {
        return 'invalid_prompt';
    }

    public function details(): array
    {
        return ['prompt' => $this->key, 'problems' => $this->problems];
    }
}
