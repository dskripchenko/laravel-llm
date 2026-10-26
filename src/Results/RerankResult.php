<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Results;

use Dskripchenko\Llm\Transport\Usage;

final class RerankResult
{
    /**
     * @param  list<array{index: int, score: float}>  $ranking  best first; `index` points into the documents passed
     */
    public function __construct(
        public readonly array $ranking,
        public readonly Usage $usage,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?int $callId,
    ) {}

    /**
     * @return list<int> document indexes, best first
     */
    public function order(): array
    {
        return array_map(fn (array $r) => $r['index'], $this->ranking);
    }
}
