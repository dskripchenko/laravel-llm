<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Results;

use Dskripchenko\Llm\Transport\ToolCall;
use Dskripchenko\Llm\Transport\Usage;

/**
 * The answer of a chat call. `data` is the structured answer (validated
 * against the schema) when one was asked for.
 */
final class LlmResult
{
    /**
     * @param  list<ToolCall>  $toolCalls
     */
    public function __construct(
        public readonly string $text,
        public readonly mixed $data,
        public readonly array $toolCalls,
        public readonly Usage $usage,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?int $callId,
        public readonly int $attempts = 1,
        public readonly ?string $finishReason = null,
        public readonly bool $replayed = false,
    ) {}

    /**
     * @return array{text: string, data: mixed, tool_calls: list<array{id: string, name: string, arguments: array<mixed>|null}>, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}, provider: string, model: string, call_id: int|null, attempts: int, finish_reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'data' => $this->data,
            'tool_calls' => array_map(fn (ToolCall $c) => $c->toArray(), $this->toolCalls),
            'usage' => $this->usage->toArray(),
            'provider' => $this->provider,
            'model' => $this->model,
            'call_id' => $this->callId,
            'attempts' => $this->attempts,
            'finish_reason' => $this->finishReason,
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data, bool $replayed = false): self
    {
        $calls = [];
        foreach (is_array($data['tool_calls'] ?? null) ? $data['tool_calls'] : [] as $call) {
            if (is_array($call)) {
                $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : null;
                $calls[] = new ToolCall((string) self::str($call['id'] ?? ''), (string) self::str($call['name'] ?? ''), $arguments, (string) json_encode($arguments));
            }
        }

        return new self(
            text: (string) self::str($data['text'] ?? ''),
            data: $data['data'] ?? null,
            toolCalls: $calls,
            usage: Usage::fromArray(is_array($data['usage'] ?? null) ? $data['usage'] : null),
            provider: (string) self::str($data['provider'] ?? ''),
            model: (string) self::str($data['model'] ?? ''),
            callId: is_numeric($data['call_id'] ?? null) ? (int) $data['call_id'] : null,
            attempts: is_numeric($data['attempts'] ?? null) ? (int) $data['attempts'] : 1,
            finishReason: self::str($data['finish_reason'] ?? null),
            replayed: $replayed,
        );
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
