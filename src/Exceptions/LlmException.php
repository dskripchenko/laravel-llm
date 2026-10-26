<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Exceptions;

use RuntimeException;

/**
 * Base of every exception the package throws. `errorCode()` is a stable
 * machine-readable code (it is also the code of an `llm.failed` event).
 */
abstract class LlmException extends RuntimeException
{
    abstract public function errorCode(): string;

    /**
     * Whether the same call may succeed later without any change
     * (a provider was down), as opposed to a configuration or input error.
     */
    public function isTransient(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return [];
    }
}
