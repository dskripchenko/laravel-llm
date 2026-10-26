<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Exceptions;

/**
 * Every route of the chain failed with a timeout, a connection error,
 * a 5xx or a 429.
 */
final class ProviderUnavailable extends LlmException
{
    /**
     * @param  list<string>  $failures  "provider/model: reason", one per route tried
     */
    public function __construct(public readonly string $purpose, public readonly array $failures)
    {
        parent::__construct("No provider could serve purpose `{$purpose}`: ".implode('; ', $failures));
    }

    public function errorCode(): string
    {
        return 'unavailable';
    }

    public function isTransient(): bool
    {
        return true;
    }

    public function details(): array
    {
        return ['failures' => $this->failures];
    }
}
