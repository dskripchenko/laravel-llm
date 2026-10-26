<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Testing;

use Dskripchenko\Llm\Models\Provider;
use Dskripchenko\Llm\Purpose;
use Dskripchenko\Llm\Transport\ChatRequest;
use Dskripchenko\Llm\Transport\EmbeddingRequest;
use Dskripchenko\Llm\Transport\RerankRequest;

/**
 * A call the fake received.
 */
final class RecordedCall
{
    /**
     * @param  'chat'|'stream'|'embed'|'rerank'  $type
     */
    public function __construct(
        public readonly string $type,
        public readonly Provider $provider,
        public readonly ChatRequest|EmbeddingRequest|RerankRequest $request,
    ) {}

    public function purpose(): Purpose
    {
        return $this->request->meta->purpose;
    }

    public function scenario(): string
    {
        return $this->request->meta->scenario;
    }

    public function promptKey(): ?string
    {
        return $this->request->meta->promptKey;
    }

    public function model(): string
    {
        return $this->request->model;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function messages(): array
    {
        return $this->request instanceof ChatRequest ? $this->request->messages : [];
    }

    /** The content of the messages with a role, joined. */
    public function text(?string $role = null): string
    {
        $texts = [];
        foreach ($this->messages() as $message) {
            if (($role === null || ($message['role'] ?? null) === $role) && is_string($message['content'] ?? null)) {
                $texts[] = $message['content'];
            }
        }
        if ($this->request instanceof EmbeddingRequest) {
            $texts = $this->request->inputs;
        }
        if ($this->request instanceof RerankRequest) {
            $texts = [$this->request->query, ...$this->request->documents];
        }

        return implode("\n", $texts);
    }

    /** Whether some message (or input) contains the text. */
    public function contains(string $needle, ?string $role = null): bool
    {
        return str_contains($this->text($role), $needle);
    }
}
