<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Transport;

/**
 * A function call the model asked for. The package does not execute tools:
 * the caller runs them and continues the conversation.
 */
final class ToolCall
{
    /**
     * @param  array<mixed>|null  $arguments  decoded arguments; null when they are not valid JSON
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?array $arguments,
        public readonly string $rawArguments,
    ) {}

    public static function make(string $id, string $name, string $rawArguments): self
    {
        $decoded = json_decode($rawArguments === '' ? '{}' : $rawArguments, true);

        return new self($id, $name, is_array($decoded) ? $decoded : null, $rawArguments);
    }

    /**
     * @return array{id: string, name: string, arguments: array<mixed>|null}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'arguments' => $this->arguments];
    }
}
