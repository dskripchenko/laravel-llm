<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Exceptions;

use Throwable;

/**
 * Raised by a transport for one call. Transient failures (timeout,
 * connection, 5xx, 429) move on to the fallback route; the rest stop.
 */
final class TransportFailure extends LlmException
{
    public function __construct(
        string $message,
        public readonly bool $transient,
        public readonly ?int $status = null,
        public readonly string $body = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unavailable(string $message, ?int $status = null, string $body = '', ?Throwable $previous = null): self
    {
        return new self($message, true, $status, $body, $previous);
    }

    public static function rejected(int $status, string $body): self
    {
        return new self("HTTP {$status}", false, $status, $body);
    }

    public function errorCode(): string
    {
        return $this->transient ? 'unavailable' : 'rejected';
    }

    public function isTransient(): bool
    {
        return $this->transient;
    }
}
