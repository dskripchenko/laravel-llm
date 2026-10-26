<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Facades;

use Dskripchenko\Llm\LlmManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Dskripchenko\Llm\PendingRequest prompt(string|\Dskripchenko\Llm\Prompts\Prompt $prompt, array<string, mixed> $variables = [])
 * @method static \Dskripchenko\Llm\PendingRequest request(\Dskripchenko\Llm\Purpose|string $purpose = \Dskripchenko\Llm\Purpose::Chat)
 * @method static \Dskripchenko\Llm\Results\EmbeddingResult embed(string|list<string> $input, ?string $scenario = null, ?\Illuminate\Database\Eloquent\Model $subject = null, ?\Dskripchenko\Llm\Quota\QuotaScope $quota = null)
 * @method static \Dskripchenko\Llm\Results\RerankResult rerank(string $query, list<string> $documents, ?int $topN = null, ?string $scenario = null, ?\Illuminate\Database\Eloquent\Model $subject = null, ?\Dskripchenko\Llm\Quota\QuotaScope $quota = null)
 * @method static \Dskripchenko\Llm\Prompts\PromptRepository prompts()
 * @method static void resolveQuotaUsing(?\Closure $resolver)
 * @method static int prune(?int $days = null)
 * @method static \Dskripchenko\Llm\Testing\FakeTransport fake(array<array-key, mixed> $responses = [])
 * @method static \Dskripchenko\Llm\Testing\FakeSequence sequence(list<mixed> $responses, mixed $whenEmpty = null)
 * @method static \Dskripchenko\Llm\Testing\FakeTransport fakeTransport()
 * @method static \Dskripchenko\Llm\Testing\FakeTransport assertSent(?\Closure $callback = null)
 * @method static \Dskripchenko\Llm\Testing\FakeTransport assertNotSent(\Closure $callback)
 * @method static \Dskripchenko\Llm\Testing\FakeTransport assertSentCount(int $count)
 * @method static \Dskripchenko\Llm\Testing\FakeTransport assertNothingSent()
 * @method static \Dskripchenko\Llm\Testing\FakeTransport assertPurpose(\Dskripchenko\Llm\Purpose|string $purpose, ?int $times = null)
 * @method static \Dskripchenko\Llm\Testing\FakeTransport assertScenario(string $scenario, ?int $times = null)
 * @method static list<\Dskripchenko\Llm\Testing\RecordedCall> recorded(?\Closure $filter = null)
 *
 * @see LlmManager
 */
final class Llm extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LlmManager::class;
    }
}
