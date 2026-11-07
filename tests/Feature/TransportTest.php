<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Feature;

use Dskripchenko\Llm\Exceptions\TransportFailure;
use Dskripchenko\Llm\Purpose;
use Dskripchenko\Llm\Tests\TestCase;
use Dskripchenko\Llm\Transport\CallMeta;
use Dskripchenko\Llm\Transport\ChatRequest;
use Dskripchenko\Llm\Transport\EmbeddingRequest;
use Dskripchenko\Llm\Transport\OpenAiCompatibleTransport;
use Dskripchenko\Llm\Transport\RerankRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class TransportTest extends TestCase
{
    private function transport(): OpenAiCompatibleTransport
    {
        return $this->app->make(OpenAiCompatibleTransport::class);
    }

    private static function chat(): ChatRequest
    {
        return new ChatRequest(
            new CallMeta(Purpose::Extract, 'test'),
            'qwen2.5:7b',
            [['role' => 'user', 'content' => 'Hi']],
            ['temperature' => 0],
            [['type' => 'function', 'function' => ['name' => 'set_label', 'parameters' => ['type' => 'object']]]],
            'auto',
            ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            'article',
        );
    }

    public function test_chat_sends_tools_and_a_json_schema_response_format(): void
    {
        Http::fake(['llm.local/*' => Http::response(self::completion('', 20, 3, [
            ['id' => 'call_9', 'type' => 'function', 'function' => ['name' => 'set_label', 'arguments' => '{"label":"guide"}']],
        ]))]);
        $provider = $this->provider('local', 'http://llm.local/v1/', ['api_key' => 'sk-secret']);

        $response = $this->transport()->chat($provider, self::chat());

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === 'http://llm.local/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer sk-secret')
                && $body['model'] === 'qwen2.5:7b'
                && $body['temperature'] === 0
                && $body['tools'][0]['function']['name'] === 'set_label'
                && $body['tool_choice'] === 'auto'
                && $body['response_format']['type'] === 'json_schema'
                && $body['response_format']['json_schema']['name'] === 'article'
                && $body['response_format']['json_schema']['schema']['properties']['a']['type'] === 'string'
                && ! isset($body['stream']);
        });
        $this->assertSame('set_label', $response->toolCalls[0]->name);
        $this->assertSame(['label' => 'guide'], $response->toolCalls[0]->arguments);
        $this->assertSame(23, $response->usage->total());
        $this->assertSame('tool_calls', $response->finishReason);
    }

    public function test_streams_an_sse_body(): void
    {
        $sse = 'data: {"choices":[{"delta":{"content":"Привет"}}]}'."\n\n"
            .'data: {"choices":[{"delta":{"content":", мир"},"finish_reason":"stop"}]}'."\n\n"
            .'data: {"choices":[],"usage":{"prompt_tokens":4,"completion_tokens":2}}'."\n\n"
            ."data: [DONE]\n\n";
        Http::fake(['llm.local/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

        $deltas = [];
        $response = $this->transport()->stream($this->provider('local', 'http://llm.local/v1'), self::chat(), function (string $d) use (&$deltas): void {
            $deltas[] = $d;
        });

        $this->assertSame(['Привет', ', мир'], $deltas);
        $this->assertSame('Привет, мир', $response->content);
        $this->assertSame(6, $response->usage->total());
        Http::assertSent(fn (Request $r) => $r->data()['stream'] === true && $r->data()['stream_options'] === ['include_usage' => true]);
    }

    public function test_embeddings_come_back_in_input_order(): void
    {
        Http::fake(['llm.local/*' => Http::response([
            'data' => [['index' => 1, 'embedding' => [0.3, 0.4]], ['index' => 0, 'embedding' => [0.1, 0.2]]],
            'usage' => ['prompt_tokens' => 6],
        ])]);

        $response = $this->transport()->embed($this->provider('local', 'http://llm.local/v1'), new EmbeddingRequest(new CallMeta(Purpose::Embed, 'e'), 'nomic-embed-text', ['a', 'b']));

        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], $response->vectors);
        $this->assertSame(6, $response->usage->total());
        Http::assertSent(fn (Request $r) => $r->url() === 'http://llm.local/v1/embeddings' && $r->data()['input'] === ['a', 'b']);
    }

    public function test_rerank_orders_by_score(): void
    {
        Http::fake(['llm.local/*' => Http::response(['results' => [['index' => 0, 'relevance_score' => 0.1], ['index' => 1, 'relevance_score' => 0.9]]])]);

        $response = $this->transport()->rerank($this->provider('local', 'http://llm.local/v1'), new RerankRequest(new CallMeta(Purpose::Rerank, 'r'), 'bge-reranker', 'q', ['x', 'y'], 1));

        $this->assertSame([['index' => 1, 'score' => 0.9], ['index' => 0, 'score' => 0.1]], $response->ranking);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://llm.local/v1/rerank' && $r->data()['top_n'] === 1);
    }

    public function test_classifies_failures(): void
    {
        $cases = [
            [Http::response('overloaded', 503), true],
            [Http::response('slow down', 429), true],
            [Http::response('model not found', 404), false],
            [Http::response('not json', 200), true],
            [fn () => throw new ConnectionException('cURL error 28: Operation timed out'), true],
        ];
        foreach ($cases as $i => [$response, $transient]) {
            Http::fake(["case{$i}.local/*" => $response]);
            $provider = $this->provider("p{$i}", "http://case{$i}.local/v1");
            try {
                $this->transport()->chat($provider, self::chat());
                $this->fail('expected a TransportFailure');
            } catch (TransportFailure $e) {
                $this->assertSame($transient, $e->transient, $e->getMessage());
            }
        }
    }
}
