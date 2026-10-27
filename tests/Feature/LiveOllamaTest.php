<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Feature;

use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Purpose;
use Dskripchenko\Llm\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/**
 * One real call against a local Ollama with the smallest chat model already
 * pulled. Opt-in: runs only when LLM_OLLAMA_URL is set (for example
 * http://127.0.0.1:11434), so the regular suite never touches the network.
 * Skipped when Ollama is not running or has no chat model; never pulls one.
 */
#[Group('live')]
final class LiveOllamaTest extends TestCase
{
    public function test_a_structured_answer_from_a_local_model(): void
    {
        $url = getenv('LLM_OLLAMA_URL');
        if (! is_string($url) || $url === '') {
            $this->markTestSkipped('Set LLM_OLLAMA_URL to run the live Ollama test');
        }
        Http::allowStrayRequests();
        $base = rtrim($url, '/');
        try {
            $tags = Http::timeout(2)->get($base.'/api/tags')->json('models');
        } catch (Throwable) {
            $this->markTestSkipped("Ollama is not running at {$base}");
        }
        $models = array_values(array_filter(is_array($tags) ? $tags : [], fn ($m) => is_array($m) && self::isChatModel($m)));
        if ($models === []) {
            $this->markTestSkipped('Ollama has no chat model pulled');
        }
        usort($models, fn (array $a, array $b) => ($a['size'] ?? 0) <=> ($b['size'] ?? 0));

        $this->route(Purpose::Classify, $this->provider('ollama', $base.'/v1', ['limits' => ['timeout' => 180]]), (string) $models[0]['name']);

        $result = Llm::request(Purpose::Classify)
            ->system('You triage issues of a task tracker.')
            ->user('Is this issue a bug or a feature request?')
            ->data('issue', 'The app crashes when I open the settings page.')
            ->schema(['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['bug', 'feature']]], 'required' => ['type']])
            ->temperature(0)
            ->run();

        $this->assertContains($result->data['type'] ?? null, ['bug', 'feature']);
        $this->assertGreaterThan(0, $result->usage->total());
    }

    /**
     * Embedding-only models (nomic-embed, bge-m3 …) answer chat requests with 400.
     *
     * @param  array<mixed>  $model
     */
    private static function isChatModel(array $model): bool
    {
        if (str_contains((string) ($model['name'] ?? ''), 'embed')) {
            return false;
        }
        $details = is_array($model['details'] ?? null) ? $model['details'] : [];
        $families = array_merge([(string) ($details['family'] ?? '')], is_array($details['families'] ?? null) ? $details['families'] : []);
        foreach ($families as $family) {
            if (str_contains((string) $family, 'bert')) {
                return false;
            }
        }

        return true;
    }
}
