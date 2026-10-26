<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Feature;

use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Purpose;
use Dskripchenko\Llm\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One real call against a local Ollama (LLM_OLLAMA_URL, default
 * http://127.0.0.1:11434) with the smallest model already pulled. Skipped
 * when Ollama is not running or has no model; never pulls one.
 */
final class LiveOllamaTest extends TestCase
{
    public function test_a_structured_answer_from_a_local_model(): void
    {
        $base = rtrim((string) (getenv('LLM_OLLAMA_URL') ?: 'http://127.0.0.1:11434'), '/');
        try {
            $tags = Http::timeout(2)->get($base.'/api/tags')->json('models');
        } catch (Throwable) {
            $this->markTestSkipped("Ollama is not running at {$base}");
        }
        $models = array_values(array_filter(is_array($tags) ? $tags : [], fn ($m) => is_array($m) && ! str_contains((string) ($m['name'] ?? ''), 'embed')));
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
}
