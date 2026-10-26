<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Transport;

use Dskripchenko\Llm\Exceptions\TransportFailure;
use Dskripchenko\Llm\Models\Provider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The OpenAI-compatible HTTP API over Laravel's Http client:
 * `/chat/completions` (plain and SSE), `/embeddings` and `/rerank`.
 */
final class OpenAiCompatibleTransport implements Transport
{
    public function __construct(
        private readonly Http $http,
        private readonly Config $config,
    ) {}

    public function chat(Provider $provider, ChatRequest $request): ChatResponse
    {
        $response = $this->post($provider, 'chat/completions', $request->payload());
        $json = $this->json($response);

        $choice = is_array($json['choices'] ?? null) ? ($json['choices'][0] ?? null) : null;
        if (! is_array($choice) || ! is_array($choice['message'] ?? null)) {
            throw TransportFailure::unavailable('The provider returned no choices', $response->status(), $response->body());
        }
        $message = $choice['message'];
        $calls = [];
        foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $call) {
            if (! is_array($call) || ! is_array($call['function'] ?? null)) {
                continue;
            }
            $function = $call['function'];
            $arguments = $function['arguments'] ?? '';
            $calls[] = ToolCall::make(
                is_string($call['id'] ?? null) ? $call['id'] : '',
                is_string($function['name'] ?? null) ? $function['name'] : '',
                is_string($arguments) ? $arguments : (string) json_encode($arguments),
            );
        }

        return new ChatResponse(
            content: is_string($message['content'] ?? null) ? $message['content'] : '',
            toolCalls: $calls,
            usage: Usage::fromArray(is_array($json['usage'] ?? null) ? $json['usage'] : null),
            finishReason: is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null,
            raw: $response->body(),
        );
    }

    public function stream(Provider $provider, ChatRequest $request, callable $onDelta): ChatResponse
    {
        $response = $this->post($provider, 'chat/completions', $request->payload(stream: true), stream: true);

        $parser = new SseParser($onDelta);
        $body = $response->toPsrResponse()->getBody();
        $raw = '';
        try {
            while (! $body->eof() && ! $parser->isDone()) {
                $bytes = $body->read(8192);
                if ($bytes === '') {
                    continue;
                }
                $raw .= $bytes;
                $parser->push($bytes);
            }
        } catch (TransportFailure $e) {
            throw $e;
        } catch (Throwable $e) {
            throw TransportFailure::unavailable('The stream broke: '.$e->getMessage(), previous: $e);
        }

        return $parser->finish($raw);
    }

    public function embed(Provider $provider, EmbeddingRequest $request): EmbeddingResponse
    {
        $response = $this->post($provider, 'embeddings', ['model' => $request->model, 'input' => $request->inputs]);
        $json = $this->json($response);

        $rows = is_array($json['data'] ?? null) ? $json['data'] : [];
        $position = fn (mixed $row): int => is_array($row) && is_numeric($row['index'] ?? null) ? (int) $row['index'] : 0;
        usort($rows, fn (mixed $a, mixed $b) => $position($a) <=> $position($b));
        $vectors = [];
        foreach ($rows as $row) {
            $embedding = is_array($row) && is_array($row['embedding'] ?? null) ? $row['embedding'] : [];
            $vectors[] = array_values(array_map(fn ($v) => is_numeric($v) ? (float) $v : 0.0, $embedding));
        }
        if (count($vectors) !== count($request->inputs)) {
            throw TransportFailure::unavailable(sprintf('Expected %d embeddings, got %d', count($request->inputs), count($vectors)), $response->status(), $response->body());
        }

        return new EmbeddingResponse($vectors, Usage::fromArray(is_array($json['usage'] ?? null) ? $json['usage'] : null));
    }

    public function rerank(Provider $provider, RerankRequest $request): RerankResponse
    {
        $payload = ['model' => $request->model, 'query' => $request->query, 'documents' => $request->documents];
        if ($request->topN !== null) {
            $payload['top_n'] = $request->topN;
        }
        $response = $this->post($provider, 'rerank', $payload);
        $json = $this->json($response);

        $ranking = [];
        foreach (is_array($json['results'] ?? null) ? $json['results'] : [] as $row) {
            if (! is_array($row) || ! is_numeric($row['index'] ?? null)) {
                continue;
            }
            $score = $row['relevance_score'] ?? $row['score'] ?? 0;
            $ranking[] = ['index' => (int) $row['index'], 'score' => is_numeric($score) ? (float) $score : 0.0];
        }
        usort($ranking, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return new RerankResponse($ranking, Usage::fromArray(is_array($json['usage'] ?? null) ? $json['usage'] : null));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(Provider $provider, string $path, array $payload, bool $stream = false): Response
    {
        try {
            $response = $this->client($provider, $stream)->post($provider->endpoint($path), $payload);
        } catch (ConnectionException $e) {
            throw TransportFailure::unavailable('Connection failed: '.$e->getMessage(), previous: $e);
        }

        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw TransportFailure::unavailable("HTTP {$status}", $status, $response->body());
        }
        if ($status >= 400) {
            throw TransportFailure::rejected($status, $response->body());
        }

        return $response;
    }

    private function client(Provider $provider, bool $stream): PendingRequest
    {
        $timeout = $provider->limit('timeout', $this->config->get('llm.http.timeout', 120));
        $connect = $this->config->get('llm.http.connect_timeout', 5);

        $request = $this->http->acceptJson()
            ->asJson()
            ->timeout(is_numeric($timeout) ? (int) $timeout : 120)
            ->connectTimeout(is_numeric($connect) ? (int) $connect : 5);
        if ($provider->api_key !== null && $provider->api_key !== '') {
            $request = $request->withToken($provider->api_key);
        }
        if ($stream) {
            $request = $request->withOptions(['stream' => true])->withHeaders(['Accept' => 'text/event-stream']);
        }

        return $request;
    }

    /**
     * @return array<mixed>
     */
    private function json(Response $response): array
    {
        $json = json_decode($response->body(), true);
        if (! is_array($json)) {
            throw TransportFailure::unavailable('The provider returned a body that is not JSON', $response->status(), $response->body());
        }

        return $json;
    }
}
