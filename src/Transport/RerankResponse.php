<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Transport;

final class RerankResponse
{
    /**
     * @param  list<array{index: int, score: float}>  $ranking  best first
     */
    public function __construct(
        public readonly array $ranking,
        public readonly Usage $usage = new Usage,
    ) {}
}
