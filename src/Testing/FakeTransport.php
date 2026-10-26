<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Testing;

use Closure;
use Dskripchenko\Llm\Models\Provider;
use Dskripchenko\Llm\Purpose;
use Dskripchenko\Llm\Transport\ChatRequest;
use Dskripchenko\Llm\Transport\ChatResponse;
use Dskripchenko\Llm\Transport\EmbeddingRequest;
use Dskripchenko\Llm\Transport\EmbeddingResponse;
use Dskripchenko\Llm\Transport\RerankRequest;
use Dskripchenko\Llm\Transport\RerankResponse;
use Dskripchenko\Llm\Transport\Transport;
use LogicException;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * The transport behind Llm::fake(): canned answers, a record of every call
 * and assertions. Routing, quotas, schema validation, retries and the call
 * log all run for real on top of it.
 *
 * - A list (`[r1, r2]`) is consumed in order by every call.
 * - A map (`['issue.summary' => r, 'classify' => r2]`) answers by scenario,
 *   then prompt key, then purpose; a value is reused for every match unless
 *   it is a FakeSequence. Unmatched calls get an empty text answer (and
 *   deterministic vectors / an identity ranking for embed / rerank).
 *
 * An answer is a string (text), an array (a structured answer), a
 * FakeResponse, a Throwable (thrown) or a Closure receiving the
 * RecordedCall and returning one of those.
 */
final class FakeTransport implements Transport
{
    /** @var list<RecordedCall> */
    private array $recorded = [];

    private ?FakeSequence $queue = null;

    /** @var array<string, mixed> */
    private array $map = [];

    /**
     * @param  array<array-key, mixed>  $responses
     */
    public function __construct(array $responses = [])
    {
        if ($responses !== [] && array_is_list($responses)) {
            $this->queue = new FakeSequence($responses);
        } else {
            foreach ($responses as $key => $response) {
                $this->map[(string) $key] = $response;
            }
        }
    }

    public function chat(Provider $provider, ChatRequest $request): ChatResponse
    {
        $call = $this->record('chat', $provider, $request);

        return $this->answer($call)->toChat(self::promptTokens($request));
    }

    public function stream(Provider $provider, ChatRequest $request, callable $onDelta): ChatResponse
    {
        $call = $this->record('stream', $provider, $request);
        $response = $this->answer($call)->toChat(self::promptTokens($request));
        foreach (preg_split('/(?<=\s)/u', $response->content, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            $onDelta($piece);
        }

        return $response;
    }

    public function embed(Provider $provider, EmbeddingRequest $request): EmbeddingResponse
    {
        $call = $this->record('embed', $provider, $request);

        return $this->answer($call, FakeResponse::embeddings(self::vectors($request->inputs)))->toEmbeddings($request->inputs);
    }

    public function rerank(Provider $provider, RerankRequest $request): RerankResponse
    {
        $call = $this->record('rerank', $provider, $request);

        return $this->answer($call, FakeResponse::text(''))->toRanking($request->documents);
    }

    /**
     * @return list<RecordedCall>
     */
    public function recorded(?Closure $filter = null): array
    {
        return $filter === null ? $this->recorded : array_values(array_filter($this->recorded, fn (RecordedCall $c) => (bool) $filter($c)));
    }

    /**
     * @param  (Closure(RecordedCall): bool)|null  $callback
     */
    public function assertSent(?Closure $callback = null): self
    {
        PHPUnit::assertNotEmpty($this->recorded($callback), 'An expected LLM call was not sent.');

        return $this;
    }

    /**
     * @param  Closure(RecordedCall): bool  $callback
     */
    public function assertNotSent(Closure $callback): self
    {
        PHPUnit::assertEmpty($this->recorded($callback), 'An unexpected LLM call was sent.');

        return $this;
    }

    public function assertSentCount(int $count): self
    {
        PHPUnit::assertCount($count, $this->recorded, "Expected {$count} LLM call(s), got ".count($this->recorded).'.');

        return $this;
    }

    public function assertNothingSent(): self
    {
        return $this->assertSentCount(0);
    }

    public function assertPurpose(Purpose|string $purpose, ?int $times = null): self
    {
        $value = $purpose instanceof Purpose ? $purpose->value : $purpose;
        $calls = $this->recorded(fn (RecordedCall $c) => $c->purpose()->value === $value);
        $times === null
            ? PHPUnit::assertNotEmpty($calls, "No LLM call with purpose `{$value}` was sent.")
            : PHPUnit::assertCount($times, $calls, "Expected {$times} LLM call(s) with purpose `{$value}`.");

        return $this;
    }

    public function assertScenario(string $scenario, ?int $times = null): self
    {
        $calls = $this->recorded(fn (RecordedCall $c) => $c->scenario() === $scenario);
        $times === null
            ? PHPUnit::assertNotEmpty($calls, "No LLM call for scenario `{$scenario}` was sent.")
            : PHPUnit::assertCount($times, $calls, "Expected {$times} LLM call(s) for scenario `{$scenario}`.");

        return $this;
    }

    /**
     * Deterministic unit vectors derived from the text.
     *
     * @param  list<string>  $inputs
     * @return list<list<float>>
     */
    public static function vectors(array $inputs, int $dimensions = 8): array
    {
        $vectors = [];
        foreach ($inputs as $input) {
            $hash = hash('sha256', $input, true);
            $vector = [];
            for ($i = 0; $i < $dimensions; $i++) {
                $vector[] = ord($hash[$i % 32]) / 255 - 0.5;
            }
            $norm = sqrt(array_sum(array_map(fn (float $v) => $v * $v, $vector))) ?: 1.0;
            $vectors[] = array_map(fn (float $v) => round($v / $norm, 6), $vector);
        }

        return $vectors;
    }

    private function record(string $type, Provider $provider, ChatRequest|EmbeddingRequest|RerankRequest $request): RecordedCall
    {
        /** @var 'chat'|'stream'|'embed'|'rerank' $type */
        return $this->recorded[] = new RecordedCall($type, $provider, $request);
    }

    private function answer(RecordedCall $call, ?FakeResponse $default = null): FakeResponse
    {
        $what = "scenario `{$call->scenario()}`";
        if ($this->queue !== null) {
            $value = $this->queue->next('the fake');
        } else {
            $value = null;
            foreach (array_filter([$call->scenario(), $call->promptKey(), $call->purpose()->value]) as $key) {
                if (array_key_exists($key, $this->map)) {
                    $value = $this->map[$key];
                    break;
                }
            }
            if ($value instanceof FakeSequence) {
                $value = $value->next($what);
            }
            $value ??= $default ?? FakeResponse::text('');
        }

        while ($value instanceof Closure) {
            $value = $value($call);
        }
        if ($value instanceof \Throwable) {
            throw $value;
        }
        if ($value === null) {
            throw new LogicException("The fake LLM has no answer for {$what}");
        }

        return FakeResponse::from($value);
    }

    private static function promptTokens(ChatRequest $request): int
    {
        $text = '';
        foreach ($request->messages as $message) {
            $text .= is_string($message['content'] ?? null) ? $message['content'] : '';
        }

        return FakeResponse::estimate($text);
    }
}
