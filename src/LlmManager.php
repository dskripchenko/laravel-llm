<?php

declare(strict_types=1);

namespace Dskripchenko\Llm;

use Closure;
use Dskripchenko\Llm\Logging\CallContext;
use Dskripchenko\Llm\Logging\Pruner;
use Dskripchenko\Llm\Prompts\Prompt;
use Dskripchenko\Llm\Prompts\PromptRepository;
use Dskripchenko\Llm\Prompts\Renderer;
use Dskripchenko\Llm\Quota\QuotaGuard;
use Dskripchenko\Llm\Quota\QuotaScope;
use Dskripchenko\Llm\Results\EmbeddingResult;
use Dskripchenko\Llm\Results\RerankResult;
use Dskripchenko\Llm\Routing\Router;
use Dskripchenko\Llm\Testing\FakeSequence;
use Dskripchenko\Llm\Testing\FakeTransport;
use Dskripchenko\Llm\Transport\Attribution;
use Dskripchenko\Llm\Transport\CallMeta;
use Dskripchenko\Llm\Transport\EmbeddingRequest;
use Dskripchenko\Llm\Transport\RerankRequest;
use Dskripchenko\Llm\Transport\Transport;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The root of the `Llm` facade.
 */
class LlmManager
{
    private ?FakeTransport $fake = null;

    public function __construct(
        private readonly Container $container,
        private readonly Executor $executor,
        private readonly PromptRepository $prompts,
        private readonly Renderer $renderer,
        private readonly QuotaGuard $quota,
        private readonly Router $router,
    ) {}

    /**
     * A request from a prompt preset (key, class or instance) and its variables.
     *
     * @param  string|class-string<Prompt>|Prompt  $prompt
     * @param  array<string, mixed>  $variables
     */
    public function prompt(string|Prompt $prompt, array $variables = []): PendingRequest
    {
        $resolved = $this->prompts->resolve($prompt);

        return new PendingRequest($this->executor, $this->renderer, $this->quota, $resolved->purpose, $resolved, $variables);
    }

    /** A request built by hand for a purpose. */
    public function request(Purpose|string $purpose = Purpose::Chat): PendingRequest
    {
        return new PendingRequest($this->executor, $this->renderer, $this->quota, $purpose instanceof Purpose ? $purpose : Purpose::from($purpose));
    }

    /**
     * Embeddings for one text or a list of texts (purpose `embed`).
     *
     * @param  string|list<string>  $input
     */
    public function embed(string|array $input, ?string $scenario = null, ?Model $subject = null, ?QuotaScope $quota = null, ?Attribution $attribution = null): EmbeddingResult
    {
        $inputs = is_string($input) ? [$input] : $input;
        $meta = new CallMeta(Purpose::Embed, $scenario ?? Purpose::Embed->value, attribution: $attribution);

        return $this->executor->embed(new EmbeddingRequest($meta, '', $inputs), $this->context($meta, $subject, $quota));
    }

    /**
     * Order documents by relevance to a query (purpose `rerank`).
     *
     * @param  list<string>  $documents
     */
    public function rerank(string $query, array $documents, ?int $topN = null, ?string $scenario = null, ?Model $subject = null, ?QuotaScope $quota = null, ?Attribution $attribution = null): RerankResult
    {
        $meta = new CallMeta(Purpose::Rerank, $scenario ?? Purpose::Rerank->value, attribution: $attribution);

        return $this->executor->rerank(new RerankRequest($meta, '', $query, $documents, $topN), $this->context($meta, $subject, $quota));
    }

    public function prompts(): PromptRepository
    {
        return $this->prompts;
    }

    /**
     * Tell which quota keys a call counts against.
     *
     * @param  (Closure(CallMeta, ?string, int|string|null): ?QuotaScope)|null  $resolver  (meta, subject type, subject id)
     */
    public function resolveQuotaUsing(?Closure $resolver): void
    {
        $this->quota->resolveUsing($resolver);
    }

    /** Prune bodies older than the configured retention; returns the rows pruned. */
    public function prune(?int $days = null): int
    {
        return $this->container->make(Pruner::class)->prune($days);
    }

    /**
     * Replace the transport with a fake. With no route configured for a
     * purpose, calls go to a synthetic local provider `fake`/`fake-model`.
     *
     * @param  array<array-key, mixed>  $responses
     */
    public function fake(array $responses = []): FakeTransport
    {
        $this->fake = new FakeTransport($responses);
        $this->container->instance(Transport::class, $this->fake);
        $this->router->synthetic = true;

        return $this->fake;
    }

    /**
     * Answers consumed in order for one key of a keyed fake.
     *
     * @param  list<mixed>  $responses
     */
    public function sequence(array $responses, mixed $whenEmpty = null): FakeSequence
    {
        return new FakeSequence($responses, $whenEmpty);
    }

    public function fakeTransport(): FakeTransport
    {
        return $this->fake ?? throw new LogicException('Llm::fake() has not been called');
    }

    /**
     * Forward assertions (`assertSent`, `assertSentCount`, …) to the fake.
     *
     * @param  array<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (str_starts_with($method, 'assert') || $method === 'recorded') {
            return $this->fakeTransport()->{$method}(...$arguments);
        }

        throw new \BadMethodCallException('Method '.static::class."::{$method} does not exist.");
    }

    private function context(CallMeta $meta, ?Model $subject, ?QuotaScope $quota): CallContext
    {
        $type = $subject?->getMorphClass();
        $key = $subject?->getKey();
        $id = is_int($key) || is_string($key) ? $key : null;

        return new CallContext($meta, $quota ?? $this->quota->scopeFor($meta, $type, $id), $type, $id);
    }
}
