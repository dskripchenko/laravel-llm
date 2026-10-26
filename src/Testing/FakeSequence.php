<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Testing;

use LogicException;

/**
 * Answers consumed in order, for one key of a keyed Llm::fake([...]).
 */
final class FakeSequence
{
    /** @var list<mixed> */
    private array $responses;

    /**
     * @param  list<mixed>  $responses
     */
    public function __construct(array $responses, private readonly mixed $whenEmpty = null)
    {
        $this->responses = $responses;
    }

    public function next(string $what): mixed
    {
        if ($this->responses === []) {
            return $this->whenEmpty ?? throw new LogicException("The fake LLM sequence for {$what} is exhausted");
        }

        return array_shift($this->responses);
    }

    public function isEmpty(): bool
    {
        return $this->responses === [];
    }
}
