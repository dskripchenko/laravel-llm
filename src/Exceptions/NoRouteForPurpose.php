<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Exceptions;

final class NoRouteForPurpose extends LlmException
{
    public function __construct(public readonly string $purpose, public readonly ?string $scenario = null)
    {
        parent::__construct("No active LLM route for purpose `{$purpose}`".($scenario !== null ? " (scenario `{$scenario}`)" : ''));
    }

    public function errorCode(): string
    {
        return 'no_route';
    }
}
