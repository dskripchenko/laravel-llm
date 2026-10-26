<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Testing;

use Dskripchenko\Llm\Exceptions\TransportFailure;
use Dskripchenko\Llm\Transport\ChatResponse;
use Dskripchenko\Llm\Transport\EmbeddingResponse;
use Dskripchenko\Llm\Transport\RerankResponse;
use Dskripchenko\Llm\Transport\ToolCall;
use Dskripchenko\Llm\Transport\Usage;
use LogicException;

/**
 * One canned answer of Llm::fake(). Plain strings (text) and arrays
 * (a structured answer, JSON-encoded) are accepted too.
 */
final class FakeResponse
{
    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  list<list<float>>|null  $vectors
     * @param  list<array{index: int, score: float}>|null  $ranking
     */
    private function __construct(
        public readonly string $content = '',
        public readonly array $toolCalls = [],
        public readonly ?Usage $usage = null,
        public readonly ?array $vectors = null,
        public readonly ?array $ranking = null,
        public readonly ?TransportFailure $failure = null,
    ) {}

    public static function text(string $content, ?Usage $usage = null): self
    {
        return new self(content: $content, usage: $usage);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function json(array $data, ?Usage $usage = null): self
    {
        return new self(content: (string) json_encode($data, JSON_UNESCAPED_UNICODE), usage: $usage);
    }

    /**
     * @param  array<mixed>  $arguments
     */
    public static function toolCall(string $name, array $arguments = [], string $id = 'call_1'): self
    {
        return new self(toolCalls: [ToolCall::make($id, $name, (string) json_encode($arguments))]);
    }

    /**
     * @param  list<list<float>>  $vectors
     */
    public static function embeddings(array $vectors): self
    {
        return new self(vectors: $vectors);
    }

    /**
     * @param  list<array{index: int, score: float}>  $ranking
     */
    public static function ranking(array $ranking): self
    {
        return new self(ranking: $ranking);
    }

    /** The provider is down (5xx / timeout): the next route is tried. */
    public static function unavailable(int $status = 503, string $message = 'Service Unavailable'): self
    {
        return new self(failure: TransportFailure::unavailable("HTTP {$status}: {$message}", $status, $message));
    }

    /** The provider refused the request (4xx). */
    public static function rejected(int $status = 400, string $body = 'Bad Request'): self
    {
        return new self(failure: TransportFailure::rejected($status, $body));
    }

    public static function from(mixed $value): self
    {
        return match (true) {
            $value instanceof self => $value,
            is_string($value) => self::text($value),
            is_array($value) => self::json($value),
            default => throw new LogicException('A fake LLM response must be a string, an array or a FakeResponse, got '.get_debug_type($value)),
        };
    }

    public function toChat(int $promptTokens): ChatResponse
    {
        $this->throwIfFailure();

        return new ChatResponse(
            content: $this->content,
            toolCalls: $this->toolCalls,
            usage: $this->usage ?? new Usage($promptTokens, self::estimate($this->content)),
            finishReason: $this->toolCalls !== [] ? 'tool_calls' : 'stop',
            raw: (string) json_encode(['fake' => true, 'content' => $this->content]),
        );
    }

    /**
     * @param  list<string>  $inputs
     */
    public function toEmbeddings(array $inputs): EmbeddingResponse
    {
        $this->throwIfFailure();

        return new EmbeddingResponse($this->vectors ?? FakeTransport::vectors($inputs), $this->usage ?? new Usage(self::estimate(implode(' ', $inputs))));
    }

    /**
     * @param  list<string>  $documents
     */
    public function toRanking(array $documents): RerankResponse
    {
        $this->throwIfFailure();
        $ranking = $this->ranking ?? array_map(fn (int $i) => ['index' => $i, 'score' => 1.0 - $i / max(1, count($documents))], array_keys($documents));

        return new RerankResponse($ranking, $this->usage ?? new Usage);
    }

    /** Roughly four characters per token, like the real thing on average. */
    public static function estimate(string $text): int
    {
        return $text === '' ? 0 : (int) ceil(mb_strlen($text) / 4);
    }

    private function throwIfFailure(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
