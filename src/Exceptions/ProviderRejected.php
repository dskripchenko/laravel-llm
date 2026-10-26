<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Exceptions;

/**
 * The provider answered with a 4xx other than 429: the request itself is
 * wrong (unknown model, context too long, bad schema). Not retried on the
 * fallback route.
 */
final class ProviderRejected extends LlmException
{
    public function __construct(
        public readonly string $provider,
        public readonly int $status,
        public readonly string $body,
    ) {
        parent::__construct("LLM provider `{$provider}` rejected the request ({$status}): ".mb_substr($body, 0, 500));
    }

    public function errorCode(): string
    {
        return 'rejected';
    }

    public function details(): array
    {
        return ['provider' => $this->provider, 'status' => $this->status];
    }
}
