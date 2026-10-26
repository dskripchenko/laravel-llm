<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Transport;

use Dskripchenko\Llm\Exceptions\TransportFailure;

/**
 * Folds the server-sent events of a streamed chat completion into a
 * ChatResponse: content deltas, tool call deltas (by index), the finish
 * reason and the usage of the final chunk.
 */
final class SseParser
{
    private string $buffer = '';

    private string $content = '';

    /** @var array<int, array{id: string, name: string, arguments: string}> */
    private array $tools = [];

    private ?string $finishReason = null;

    private Usage $usage;

    private bool $done = false;

    /** @var callable(string): void */
    private $onDelta;

    /**
     * @param  callable(string): void  $onDelta
     */
    public function __construct(callable $onDelta)
    {
        $this->onDelta = $onDelta;
        $this->usage = new Usage;
    }

    /** Feed raw bytes as they arrive. */
    public function push(string $bytes): void
    {
        $this->buffer .= $bytes;
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $pos), "\r");
            $this->buffer = substr($this->buffer, $pos + 1);
            $this->line($line);
        }
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    /** The stream ended: flush what is left and build the response. */
    public function finish(string $raw = ''): ChatResponse
    {
        if ($this->buffer !== '') {
            $this->line(rtrim($this->buffer, "\r"));
            $this->buffer = '';
        }
        ksort($this->tools);
        $calls = array_values(array_map(
            fn (array $t) => ToolCall::make($t['id'], $t['name'], $t['arguments']),
            $this->tools,
        ));

        return new ChatResponse($this->content, $calls, $this->usage, $this->finishReason, $raw);
    }

    private function line(string $line): void
    {
        if ($this->done || ! str_starts_with($line, 'data:')) {
            return;   // comments, `event:`, `id:`, blank separators
        }
        $data = trim(substr($line, 5));
        if ($data === '[DONE]') {
            $this->done = true;

            return;
        }
        $chunk = json_decode($data, true);
        if (! is_array($chunk)) {
            return;
        }
        if (isset($chunk['error'])) {
            $error = $chunk['error'];
            $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : (is_string($error) ? $error : 'stream error');

            throw TransportFailure::unavailable('Stream error: '.$message);
        }
        if (isset($chunk['usage']) && is_array($chunk['usage'])) {
            $this->usage = Usage::fromArray($chunk['usage']);
        }
        $choice = is_array($chunk['choices'] ?? null) ? ($chunk['choices'][0] ?? null) : null;
        if (! is_array($choice)) {
            return;
        }
        if (is_string($choice['finish_reason'] ?? null)) {
            $this->finishReason = $choice['finish_reason'];
        }
        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
        if (is_string($delta['content'] ?? null) && $delta['content'] !== '') {
            $this->content .= $delta['content'];
            ($this->onDelta)($delta['content']);
        }
        foreach (is_array($delta['tool_calls'] ?? null) ? $delta['tool_calls'] : [] as $position => $call) {
            if (! is_array($call)) {
                continue;
            }
            $index = is_int($call['index'] ?? null) ? $call['index'] : (int) $position;
            $this->tools[$index] ??= ['id' => '', 'name' => '', 'arguments' => ''];
            if (is_string($call['id'] ?? null)) {
                $this->tools[$index]['id'] = $call['id'];
            }
            $function = is_array($call['function'] ?? null) ? $call['function'] : [];
            if (is_string($function['name'] ?? null)) {
                $this->tools[$index]['name'] .= $function['name'];
            }
            if (is_string($function['arguments'] ?? null)) {
                $this->tools[$index]['arguments'] .= $function['arguments'];
            }
        }
    }
}
