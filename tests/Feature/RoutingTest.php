<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Feature;

use Dskripchenko\Llm\CallStatus;
use Dskripchenko\Llm\Exceptions\CloudProviderNotEnabled;
use Dskripchenko\Llm\Exceptions\NoRouteForPurpose;
use Dskripchenko\Llm\Exceptions\ProviderRejected;
use Dskripchenko\Llm\Exceptions\ProviderUnavailable;
use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Models\Call;
use Dskripchenko\Llm\Models\Provider;
use Dskripchenko\Llm\Purpose;
use Dskripchenko\Llm\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class RoutingTest extends TestCase
{
    public function test_falls_back_when_the_primary_returns_5xx(): void
    {
        Http::fake([
            'gpu.local/*' => Http::response('CUDA out of memory', 500),
            'cpu.local/*' => Http::response(self::completion('summary')),
        ]);
        $fallback = $this->route(Purpose::Summarize, $this->provider('cpu', 'http://cpu.local/v1'), 'llama3.2:3b');
        $this->route(Purpose::Summarize, $this->provider('gpu', 'http://gpu.local/v1'), 'qwen2.5:32b', $fallback, ['options' => ['temperature' => 0.1]]);

        $result = Llm::request('summarize')->user('Summarize this')->run();

        $this->assertSame('summary', $result->text);
        $this->assertSame('cpu', $result->provider);
        $this->assertSame('llama3.2:3b', $result->model);
        Http::assertSentInOrder([
            fn (Request $r) => $r->url() === 'http://gpu.local/v1/chat/completions' && $r->data()['model'] === 'qwen2.5:32b' && $r->data()['temperature'] === 0.1,
            fn (Request $r) => $r->url() === 'http://cpu.local/v1/chat/completions' && $r->data()['model'] === 'llama3.2:3b' && ! isset($r->data()['temperature']),
        ]);
        $this->assertSame(
            [['gpu', CallStatus::Unavailable], ['cpu', CallStatus::Ok]],
            Call::query()->orderBy('id')->get()->map(fn (Call $c) => [$c->provider, $c->status])->all(),
        );
    }

    public function test_falls_back_on_timeout(): void
    {
        Http::fake([
            'gpu.local/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 120000 milliseconds'),
            'cpu.local/*' => Http::response(self::completion('ok')),
        ]);
        $fallback = $this->route(Purpose::Chat, $this->provider('cpu', 'http://cpu.local/v1'), 'small');
        $this->route(Purpose::Chat, $this->provider('gpu', 'http://gpu.local/v1'), 'big', $fallback);

        $this->assertSame('cpu', Llm::request()->user('hi')->run()->provider);
        $this->assertStringContainsString('timed out', (string) Call::query()->where('provider', 'gpu')->value('error'));
    }

    public function test_all_routes_down_is_a_transient_unavailable(): void
    {
        Http::fake(['*' => Http::response('down', 502)]);
        $fallback = $this->route(Purpose::Chat, $this->provider('b', 'http://b.local/v1'), 'm');
        $this->route(Purpose::Chat, $this->provider('a', 'http://a.local/v1'), 'm', $fallback);

        try {
            Llm::request()->user('hi')->run();
            $this->fail('expected ProviderUnavailable');
        } catch (ProviderUnavailable $e) {
            $this->assertTrue($e->isTransient());
            $this->assertCount(2, $e->failures);
        }
    }

    public function test_a_4xx_is_not_retried_on_the_fallback(): void
    {
        Http::fake(['a.local/*' => Http::response('{"error":"context length exceeded"}', 400), 'b.local/*' => Http::response(self::completion('x'))]);
        $fallback = $this->route(Purpose::Chat, $this->provider('b', 'http://b.local/v1'), 'm');
        $this->route(Purpose::Chat, $this->provider('a', 'http://a.local/v1'), 'm', $fallback);

        $this->expectException(ProviderRejected::class);
        try {
            Llm::request()->user('hi')->run();
        } finally {
            Http::assertSentCount(1);
            $this->assertSame(CallStatus::Rejected, Call::query()->sole()->status);
        }
    }

    public function test_refuses_a_cloud_provider_that_is_not_enabled_and_uses_the_local_fallback(): void
    {
        Http::fake(['local.test/*' => Http::response(self::completion('local answer')), '*' => Http::response(self::completion('cloud answer'))]);
        $local = $this->route(Purpose::Extract, $this->provider('ollama', 'http://local.test/v1'), 'qwen2.5:7b');
        $this->route(Purpose::Extract, $this->provider('openai', 'https://api.cloud.test/v1', ['is_cloud' => true, 'api_key' => 'sk']), 'gpt', $local);

        $this->assertSame('local answer', Llm::request(Purpose::Extract)->user('x')->run()->text);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'cloud.test'));
    }

    public function test_refuses_when_only_a_disabled_cloud_provider_is_left(): void
    {
        Http::fake(['*' => Http::response(self::completion('from cloud'))]);
        $cloud = $this->provider('openai', 'https://api.cloud.test/v1', ['is_cloud' => true]);
        $this->route(Purpose::Chat, $cloud, 'gpt');

        try {
            Llm::request()->user('x')->run();
            $this->fail('expected CloudProviderNotEnabled');
        } catch (CloudProviderNotEnabled $e) {
            $this->assertSame(['openai'], $e->providers);
            $this->assertSame('cloud_not_enabled', $e->errorCode());
        }
        Http::assertNothingSent();

        // The administrator enables it explicitly.
        $cloud->forceFill(['cloud_enabled' => true])->save();
        $this->assertSame('from cloud', Llm::request()->user('x')->run()->text);
    }

    public function test_a_scenario_route_wins_over_the_purpose_route_and_disabled_providers_are_skipped(): void
    {
        Http::fake(['*' => Http::response(self::completion('ok'))]);
        $provider = $this->provider('local', 'http://local.test/v1');
        $this->route(Purpose::Chat, $provider, 'general');
        $this->route(Purpose::Chat, $provider, 'special', null, ['scenario' => 'wiki.answer']);

        $this->assertSame('special', Llm::request()->scenario('wiki.answer')->user('q')->run()->model);
        $this->assertSame('general', Llm::request()->scenario('other')->user('q')->run()->model);

        $provider->forceFill(['is_enabled' => false])->save();
        $this->expectException(NoRouteForPurpose::class);
        Llm::request()->user('q')->run();
    }

    public function test_no_route_and_a_route_cycle(): void
    {
        $a = $this->route(Purpose::Chat, $this->provider('a', 'http://a.local/v1'), 'm');
        $b = $this->route(Purpose::Chat, $this->provider('b', 'http://b.local/v1'), 'm', $a);
        $a->forceFill(['fallback_route_id' => $b->id])->save();
        Http::fake(['*' => Http::response('down', 503)]);

        try {
            Llm::request()->user('x')->run();
            $this->fail('expected ProviderUnavailable');
        } catch (ProviderUnavailable $e) {
            $this->assertCount(2, $e->failures);
        }

        $this->expectException(NoRouteForPurpose::class);
        Llm::embed('text');
    }

    public function test_the_api_key_is_encrypted_at_rest_and_never_logged(): void
    {
        Http::fake(['*' => Http::response(self::completion('ok'))]);
        $this->route(Purpose::Chat, $this->provider('p', 'http://p.local/v1', ['api_key' => 'sk-very-secret']), 'm');

        Llm::request()->user('x')->run();

        $raw = DB::table('llm_providers')->value('api_key');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('sk-very-secret', $raw);
        $this->assertSame('sk-very-secret', Provider::query()->sole()->api_key);
        $this->assertStringNotContainsString('sk-very-secret', (string) json_encode(DB::table('llm_calls')->get()));
        $this->assertArrayNotHasKey('api_key', Provider::query()->sole()->toArray());
    }

    public function test_max_output_tokens_limit_caps_the_request(): void
    {
        Http::fake(['*' => Http::response(self::completion('ok'))]);
        $this->route(Purpose::Chat, $this->provider('p', 'http://p.local/v1', ['limits' => ['max_output_tokens' => 256]]), 'm');

        Llm::request()->user('x')->maxTokens(4000)->run();
        Llm::request()->user('x')->run();

        Http::assertSent(fn (Request $r) => $r->data()['max_tokens'] === 256);
        Http::assertSentCount(2);
    }

    public function test_embed_and_rerank_go_through_their_routes(): void
    {
        Http::fake([
            'p.local/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => [1, 0]]], 'usage' => ['prompt_tokens' => 3]]),
            'p.local/v1/rerank' => Http::response(['results' => [['index' => 1, 'relevance_score' => 0.8], ['index' => 0, 'relevance_score' => 0.2]]]),
        ]);
        $p = $this->provider('p', 'http://p.local/v1');
        $this->route(Purpose::Embed, $p, 'nomic-embed-text');
        $this->route(Purpose::Rerank, $p, 'bge-reranker-v2-m3');

        $embedding = Llm::embed('hello', scenario: 'search.index');
        $this->assertSame([1.0, 0.0], $embedding->first());
        $this->assertSame(2, $embedding->dimensions());
        $this->assertSame([1, 0], Llm::rerank('q', ['a', 'b'])->order());

        $this->assertSame(['embed', 'rerank'], Call::query()->orderBy('id')->pluck('purpose')->map(fn (Purpose $p) => $p->value)->all());
        $this->assertSame(3, Call::query()->where('purpose', 'embed')->sole()->total_tokens);
    }
}
