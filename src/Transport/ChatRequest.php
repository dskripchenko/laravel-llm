<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Transport;

/**
 * One `POST {base}/chat/completions`.
 */
final class ChatRequest
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options  temperature, max_tokens, top_p, seed, stop…
     * @param  list<array<string, mixed>>  $tools  OpenAI tool definitions
     * @param  array<string, mixed>|null  $schema  JSON schema of a structured answer
     */
    public function __construct(
        public readonly CallMeta $meta,
        public readonly string $model,
        public readonly array $messages,
        public readonly array $options = [],
        public readonly array $tools = [],
        public readonly mixed $toolChoice = null,
        public readonly ?array $schema = null,
        public readonly string $schemaName = 'answer',
    ) {}

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function withMessages(array $messages): self
    {
        return new self($this->meta, $this->model, $messages, $this->options, $this->tools, $this->toolChoice, $this->schema, $this->schemaName);
    }

    public function withModel(string $model): self
    {
        return new self($this->meta, $model, $this->messages, $this->options, $this->tools, $this->toolChoice, $this->schema, $this->schemaName);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function withOptions(array $options): self
    {
        return new self($this->meta, $this->model, $this->messages, $options, $this->tools, $this->toolChoice, $this->schema, $this->schemaName);
    }

    /**
     * The JSON body of the request.
     *
     * @return array<string, mixed>
     */
    public function payload(bool $stream = false): array
    {
        $payload = ['model' => $this->model, 'messages' => $this->messages] + $this->options;
        if ($this->tools !== []) {
            $payload['tools'] = $this->tools;
            if ($this->toolChoice !== null) {
                $payload['tool_choice'] = $this->toolChoice;
            }
        }
        if ($this->schema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => $this->schemaName, 'schema' => $this->schema, 'strict' => true],
            ];
        }
        if ($stream) {
            $payload['stream'] = true;
            $payload['stream_options'] = ['include_usage' => true];
        }

        return $payload;
    }
}
