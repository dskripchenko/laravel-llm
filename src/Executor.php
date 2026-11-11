<?php

declare(strict_types=1);

namespace Dskripchenko\Llm;

use Closure;
use Dskripchenko\Llm\Exceptions\InvalidStructuredOutput;
use Dskripchenko\Llm\Exceptions\ProviderRejected;
use Dskripchenko\Llm\Exceptions\ProviderUnavailable;
use Dskripchenko\Llm\Exceptions\TransportFailure;
use Dskripchenko\Llm\Logging\CallContext;
use Dskripchenko\Llm\Logging\CallLogger;
use Dskripchenko\Llm\Models\Call;
use Dskripchenko\Llm\Quota\QuotaGuard;
use Dskripchenko\Llm\Results\EmbeddingResult;
use Dskripchenko\Llm\Results\LlmResult;
use Dskripchenko\Llm\Results\RerankResult;
use Dskripchenko\Llm\Routing\Candidate;
use Dskripchenko\Llm\Routing\Router;
use Dskripchenko\Llm\Schema\JsonOutput;
use Dskripchenko\Llm\Schema\Validator;
use Dskripchenko\Llm\Transport\ChatRequest;
use Dskripchenko\Llm\Transport\ChatResponse;
use Dskripchenko\Llm\Transport\EmbeddingRequest;
use Dskripchenko\Llm\Transport\EmbeddingResponse;
use Dskripchenko\Llm\Transport\RerankRequest;
use Dskripchenko\Llm\Transport\RerankResponse;
use Dskripchenko\Llm\Transport\Transport;
use Dskripchenko\Llm\Transport\Usage;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;

/**
 * Runs a request: quota → route chain with fallback → schema validation
 * with bounded retries → call log.
 *
 * @internal
 */
final class Executor
{
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly QuotaGuard $quota,
        private readonly CallLogger $logger,
        private readonly Validator $validator,
        private readonly Config $config,
    ) {}

    /**
     * @param  (callable(string): void)|null  $onDelta  streams when given
     */
    public function chat(ChatRequest $request, CallContext $context, ?callable $onDelta = null): LlmResult
    {
        if (($replay = $this->replay($context)) !== null) {
            return $replay;
        }

        $candidates = $this->router->candidates($context->meta->purpose, $context->meta->scenario);
        $maxAttempts = $request->schema !== null ? max(1, $this->int('llm.structured.max_attempts', 3)) : 1;
        $usage = new Usage;
        $errors = [];
        $content = '';

        // Once a delta reached the caller, a failure cannot move on to the
        // fallback route: the caller would get two answers glued together.
        $emitted = false;
        $delta = $onDelta === null ? null : function (string $text) use (&$emitted, $onDelta): void {
            $emitted = true;
            $onDelta($text);
        };

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->quota->check($context->quota);

            [$response, $candidate, $sent, $ms] = $this->chain(
                $context,
                $candidates,
                $attempt,
                fn (Candidate $c) => $this->prepare($request, $c),
                fn (Candidate $c, ChatRequest $r) => $delta !== null
                    ? $this->transport()->stream($c->provider, $r, $delta)
                    : $this->transport()->chat($c->provider, $r),
                fn (ChatRequest $r) => $this->body($r, $onDelta !== null),
                function () use (&$emitted): bool {
                    return $emitted;
                },
            );
            /** @var ChatResponse $response */
            /** @var ChatRequest $sent */
            $usage = $usage->plus($response->usage);
            $content = $response->content;

            $data = null;
            if ($request->schema !== null) {
                $parsed = JsonOutput::parse($content);
                $errors = $parsed['ok'] ? $this->validator->validate($parsed['value'], $request->schema) : [(string) $parsed['error']];
                if ($errors !== []) {
                    $this->logger->record($context, $candidate, CallStatus::InvalidOutput, $attempt, $ms, $response->usage,
                        $this->body($sent, $onDelta !== null), $response->raw !== '' ? $response->raw : $content, null, implode('; ', $errors));
                    $request = $request->withMessages([
                        ...$request->messages,
                        ['role' => 'assistant', 'content' => $content],
                        ['role' => 'user', 'content' => self::feedback($errors)],
                    ]);

                    continue;
                }
                $data = JsonOutput::toArray($parsed['value']);
            }

            $result = new LlmResult(
                text: $content,
                data: $data,
                toolCalls: $response->toolCalls,
                usage: $usage,
                provider: $candidate->provider->name,
                model: $candidate->model,
                callId: null,
                attempts: $attempt,
                finishReason: $response->finishReason,
            );
            $call = $this->logger->record($context, $candidate, CallStatus::Ok, $attempt, $ms, $response->usage,
                $this->body($sent, $onDelta !== null), $response->raw !== '' ? $response->raw : $content, $result->toArray());
            if ($call !== null) {
                $result = LlmResult::fromArray(['call_id' => $call->id] + $result->toArray());
                if ($this->logger->keepsBodies()) {
                    $call->forceFill(['result' => $result->toArray()])->save();
                }
            }

            return $result;
        }

        throw new InvalidStructuredOutput($errors, $content, $maxAttempts);
    }

    public function embed(EmbeddingRequest $request, CallContext $context): EmbeddingResult
    {
        $candidates = $this->router->candidates($context->meta->purpose, $context->meta->scenario);
        $this->quota->check($context->quota);

        [$response, $candidate, $sent, $ms] = $this->chain(
            $context,
            $candidates,
            1,
            fn (Candidate $c) => $request->withModel($c->model),
            fn (Candidate $c, EmbeddingRequest $r) => $this->transport()->embed($c->provider, $r),
            fn (EmbeddingRequest $r) => (string) json_encode(['model' => $r->model, 'input' => $r->inputs], JSON_UNESCAPED_UNICODE),
        );
        /** @var EmbeddingResponse $response */
        /** @var EmbeddingRequest $sent */
        $call = $this->logger->record($context, $candidate, CallStatus::Ok, 1, $ms, $response->usage,
            (string) json_encode(['model' => $sent->model, 'input' => $sent->inputs], JSON_UNESCAPED_UNICODE),
            (string) json_encode(['count' => count($response->vectors), 'dimensions' => count($response->vectors[0] ?? [])]));

        return new EmbeddingResult($response->vectors, $response->usage, $candidate->provider->name, $candidate->model, $call?->id);
    }

    public function rerank(RerankRequest $request, CallContext $context): RerankResult
    {
        $candidates = $this->router->candidates($context->meta->purpose, $context->meta->scenario);
        $this->quota->check($context->quota);

        $encode = fn (RerankRequest $r) => (string) json_encode(['model' => $r->model, 'query' => $r->query, 'documents' => $r->documents, 'top_n' => $r->topN], JSON_UNESCAPED_UNICODE);
        [$response, $candidate, $sent, $ms] = $this->chain(
            $context,
            $candidates,
            1,
            fn (Candidate $c) => $request->withModel($c->model),
            fn (Candidate $c, RerankRequest $r) => $this->transport()->rerank($c->provider, $r),
            $encode,
        );
        /** @var RerankResponse $response */
        /** @var RerankRequest $sent */
        $call = $this->logger->record($context, $candidate, CallStatus::Ok, 1, $ms, $response->usage,
            $encode($sent), (string) json_encode($response->ranking), ['ranking' => $response->ranking]);

        return new RerankResult($response->ranking, $response->usage, $candidate->provider->name, $candidate->model, $call?->id);
    }

    /**
     * Try each candidate in turn; transient failures move on, a rejection
     * stops. Failed calls are logged here, the successful one by the caller.
     *
     * @template TRequest of object
     * @template TResponse of object
     *
     * @param  non-empty-list<Candidate>  $candidates
     * @param  Closure(Candidate): TRequest  $prepare
     * @param  Closure(Candidate, TRequest): TResponse  $send
     * @param  Closure(TRequest): string  $encode
     * @param  (Closure(): bool)|null  $committed  true once part of an answer reached the caller
     * @return array{TResponse, Candidate, TRequest, int}
     */
    private function chain(CallContext $context, array $candidates, int $attempt, Closure $prepare, Closure $send, Closure $encode, ?Closure $committed = null): array
    {
        $failures = [];
        foreach ($candidates as $candidate) {
            $request = $prepare($candidate);
            $started = hrtime(true);
            try {
                return [$send($candidate, $request), $candidate, $request, self::ms($started)];
            } catch (TransportFailure $e) {
                $this->logger->record($context, $candidate, $e->transient ? CallStatus::Unavailable : CallStatus::Rejected,
                    $attempt, self::ms($started), new Usage, $encode($request), $e->body !== '' ? $e->body : null, null, $e->getMessage());
                if (! $e->transient) {
                    throw new ProviderRejected($candidate->provider->name, $e->status ?? 0, $e->body);
                }
                $failures[] = $candidate->label().': '.$e->getMessage();
                if ($committed !== null && $committed()) {
                    break;
                }
            }
        }

        throw new ProviderUnavailable($context->meta->purpose->value, $failures);
    }

    /**
     * Options: route defaults < prompt/request options; `max_tokens` capped
     * by the provider's `max_output_tokens` limit.
     */
    private function prepare(ChatRequest $request, Candidate $candidate): ChatRequest
    {
        $options = array_merge($candidate->options, $request->options);
        $cap = $candidate->provider->limit('max_output_tokens');
        if (is_numeric($cap)) {
            $current = $options['max_tokens'] ?? null;
            $options['max_tokens'] = is_numeric($current) ? min((int) $current, (int) $cap) : (int) $cap;
        }

        return $request->withModel($candidate->model)->withOptions($options);
    }

    /**
     * A successful call already made under the same idempotency key.
     */
    private function replay(CallContext $context): ?LlmResult
    {
        if ($context->idempotencyKey === null) {
            return null;
        }
        $query = Call::query()->where('idempotency_key', $context->idempotencyKey)->where('status', CallStatus::Ok->value);
        $query->whereNotNull('result')->orderByDesc('id');
        $call = $query->first();

        return $call !== null && is_array($call->result) ? LlmResult::fromArray($call->result, replayed: true) : null;
    }

    /**
     * @param  list<string>  $errors
     */
    public static function feedback(array $errors): string
    {
        return "Your previous answer does not match the required JSON schema:\n- ".implode("\n- ", $errors)
            ."\nAnswer again with only the corrected JSON value, no prose and no Markdown.";
    }

    private function body(ChatRequest $request, bool $stream): string
    {
        return (string) json_encode($request->payload($stream), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function transport(): Transport
    {
        return $this->container->make(Transport::class);
    }

    private function int(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private static function ms(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
