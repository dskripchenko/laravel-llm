<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Prompts;

use Dskripchenko\Llm\Purpose;

/**
 * A preset with the tenant's active override applied.
 */
final class ResolvedPrompt
{
    /**
     * @param  array<string, string>  $variables
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $key,
        public readonly ?int $version,
        public readonly Purpose $purpose,
        public readonly ?string $system,
        public readonly string $user,
        public readonly array $variables,
        public readonly ?array $schema,
        public readonly array $options,
    ) {}
}
