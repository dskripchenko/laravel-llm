<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Results;

use Dskripchenko\Llm\Transport\Usage;

final class EmbeddingResult
{
    /**
     * @param  list<list<float>>  $vectors  in the order of the inputs
     */
    public function __construct(
        public readonly array $vectors,
        public readonly Usage $usage,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?int $callId,
    ) {}

    /**
     * @return list<float>
     */
    public function first(): array
    {
        return $this->vectors[0] ?? [];
    }

    public function dimensions(): int
    {
        return count($this->first());
    }
}
