<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Transport;

/**
 * One `POST {base}/rerank` (the Jina/Cohere shape served by vLLM and
 * llama.cpp server; not part of the OpenAI API).
 */
final class RerankRequest
{
    /**
     * @param  list<string>  $documents
     */
    public function __construct(
        public readonly CallMeta $meta,
        public readonly string $model,
        public readonly string $query,
        public readonly array $documents,
        public readonly ?int $topN = null,
    ) {}

    public function withModel(string $model): self
    {
        return new self($this->meta, $model, $this->query, $this->documents, $this->topN);
    }
}
