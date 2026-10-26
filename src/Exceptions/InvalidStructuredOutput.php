<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Exceptions;

/**
 * The model's answer did not match the JSON schema after every attempt.
 */
final class InvalidStructuredOutput extends LlmException
{
    /**
     * @param  list<string>  $errors  of the last attempt
     */
    public function __construct(
        public readonly array $errors,
        public readonly string $lastOutput,
        public readonly int $attempts,
    ) {
        parent::__construct("The model's answer did not match the schema after {$attempts} attempt(s): ".implode('; ', $errors));
    }

    public function errorCode(): string
    {
        return 'invalid_output';
    }

    public function details(): array
    {
        return ['errors' => $this->errors, 'attempts' => $this->attempts];
    }
}
