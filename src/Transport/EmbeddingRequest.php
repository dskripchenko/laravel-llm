<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Transport;

/**
 * One `POST {base}/embeddings`.
 */
final class EmbeddingRequest
{
    /**
     * @param  list<string>  $inputs
     */
    public function __construct(
        public readonly CallMeta $meta,
        public readonly string $model,
        public readonly array $inputs,
    ) {}

    public function withModel(string $model): self
    {
        return new self($this->meta, $model, $this->inputs);
    }
}
