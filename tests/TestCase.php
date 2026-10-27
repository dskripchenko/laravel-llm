<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests;

use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\LlmServiceProvider;
use Dskripchenko\Llm\Models\Provider;
use Dskripchenko\Llm\Models\Route;
use Dskripchenko\Llm\Purpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Base;

abstract class TestCase extends Base
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite never talks to a real server: every request must be faked.
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [LlmServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Llm' => Llm::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function provider(string $name, string $baseUrl, array $attributes = []): Provider
    {
        return Provider::query()->create(['name' => $name, 'base_url' => $baseUrl] + $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function route(Purpose $purpose, Provider $provider, string $model, ?Route $fallback = null, array $attributes = []): Route
    {
        return Route::query()->create([
            'purpose' => $purpose,
            'provider_id' => $provider->id,
            'model' => $model,
            'fallback_route_id' => $fallback?->id,
        ] + $attributes);
    }

    /**
     * An OpenAI-compatible chat completion body.
     *
     * @param  list<array<string, mixed>>  $toolCalls
     * @return array<string, mixed>
     */
    protected static function completion(string $content, int $prompt = 10, int $completion = 5, array $toolCalls = []): array
    {
        $message = ['role' => 'assistant', 'content' => $content];
        if ($toolCalls !== []) {
            $message['tool_calls'] = $toolCalls;
        }

        return [
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $toolCalls !== [] ? 'tool_calls' : 'stop']],
            'usage' => ['prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => $prompt + $completion],
        ];
    }
}
