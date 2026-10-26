<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Transport;

final class EmbeddingResponse
{
    /**
     * @param  list<list<float>>  $vectors  in the order of the inputs
     */
    public function __construct(
        public readonly array $vectors,
        public readonly Usage $usage = new Usage,
    ) {}
}
