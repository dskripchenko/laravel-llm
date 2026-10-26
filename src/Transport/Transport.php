<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Transport;

use Dskripchenko\Llm\Exceptions\TransportFailure;
use Dskripchenko\Llm\Models\Provider;

/**
 * Talks to one provider. Implementations throw TransportFailure; whether it
 * is transient decides if the fallback route is tried.
 */
interface Transport
{
    /** @throws TransportFailure */
    public function chat(Provider $provider, ChatRequest $request): ChatResponse;

    /**
     * Stream the answer; `$onDelta` receives content as it arrives.
     *
     * @param  callable(string): void  $onDelta
     *
     * @throws TransportFailure
     */
    public function stream(Provider $provider, ChatRequest $request, callable $onDelta): ChatResponse;

    /** @throws TransportFailure */
    public function embed(Provider $provider, EmbeddingRequest $request): EmbeddingResponse;

    /** @throws TransportFailure */
    public function rerank(Provider $provider, RerankRequest $request): RerankResponse;
}
