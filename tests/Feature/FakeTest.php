<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Feature;

use Dskripchenko\Llm\Exceptions\ProviderRejected;
use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Models\Call;
use Dskripchenko\Llm\Purpose;
use Dskripchenko\Llm\Testing\FakeResponse;
use Dskripchenko\Llm\Testing\RecordedCall;
use Dskripchenko\Llm\Tests\Fixtures\ClassifyArticle;
use Dskripchenko\Llm\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;

final class FakeTest extends TestCase
{
    public function test_a_list_is_consumed_in_order_without_any_route_configured(): void
    {
        Http::fake();
        Llm::fake(['first', ['type' => 'guide', 'level' => 1], FakeResponse::toolCall('set_label', ['label' => 'guide'])]);

        $this->assertSame('first', Llm::request()->user('a')->run()->text);
        $this->assertSame(['type' => 'guide', 'level' => 1], Llm::prompt(ClassifyArticle::class, ['body' => 'b'])->run()->data);
        $tool = Llm::request()->user('c')->tools([['type' => 'function', 'function' => ['name' => 'set_label']]])->run();
        $this->assertSame(['label' => 'guide'], $tool->toolCalls[0]->arguments);

        Llm::assertSentCount(3);
        Llm::assertPurpose(Purpose::Classify, 1);
        Llm::assertScenario('article.classify');
        Llm::assertSent(fn (RecordedCall $c) => $c->contains('b', 'user') && $c->model() === 'fake-model' && $c->provider->name === 'fake');
        Llm::assertNotSent(fn (RecordedCall $c) => $c->contains('nothing like this'));
        Http::assertNothingSent();
        $this->assertSame(3, Call::query()->count());

        $this->expectException(LogicException::class);
        Llm::request()->user('d')->run();
    }

    public function test_a_map_answers_by_scenario_prompt_key_or_purpose(): void
    {
        Llm::fake([
            'article.classify' => Llm::sequence([['type' => 'chore'], ['type' => 'guide', 'level' => 3]]),
            'wiki.answer' => fn (RecordedCall $c) => 'You asked: '.$c->text('user'),
            'summarize' => 'a summary',
        ]);

        // The first answer is invalid against the schema: the real retry runs on the fake.
        $this->assertSame(['type' => 'guide', 'level' => 3], Llm::prompt(ClassifyArticle::class, ['body' => 'b'])->run()->data);
        $this->assertSame('You asked: why?', Llm::request()->scenario('wiki.answer')->user('why?')->run()->text);
        $this->assertSame('a summary', Llm::request(Purpose::Summarize)->user('x')->run()->text);
        $this->assertSame('a summary', Llm::request(Purpose::Summarize)->scenario('again')->user('x')->run()->text);
        $this->assertSame('', Llm::request()->user('unmatched')->run()->text);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('exhausted');
        Llm::prompt(ClassifyArticle::class, ['body' => 'b'])->run();
    }

    public function test_failures_can_be_faked_and_exercise_the_real_fallback(): void
    {
        $fake = Llm::fake([FakeResponse::unavailable(), 'from fallback', FakeResponse::rejected(400)]);
        $fallback = $this->route(Purpose::Chat, $this->provider('cpu', 'http://cpu.local/v1'), 'small');
        $this->route(Purpose::Chat, $this->provider('gpu', 'http://gpu.local/v1'), 'big', $fallback);

        $result = Llm::request()->user('x')->run();
        $this->assertSame(['from fallback', 'cpu'], [$result->text, $result->provider]);
        $this->assertSame(['big', 'small'], array_map(fn (RecordedCall $c) => $c->model(), $fake->recorded()));

        $this->expectException(ProviderRejected::class);
        Llm::request()->user('y')->run();
    }

    public function test_streams_embeddings_and_rerank_have_defaults(): void
    {
        Llm::fake(['stream' => 'one two three']);

        $deltas = [];
        Llm::request()->scenario('stream')->user('x')->stream(function (string $d) use (&$deltas): void {
            $deltas[] = $d;
        });
        $this->assertSame(['one ', 'two ', 'three'], $deltas);

        $a = Llm::embed(['same', 'other']);
        $b = Llm::embed('same');
        $this->assertSame($a->vectors[0], $b->first());
        $this->assertNotSame($a->vectors[0], $a->vectors[1]);
        $this->assertSame(8, $b->dimensions());
        $this->assertSame([0, 1, 2], Llm::rerank('q', ['a', 'b', 'c'])->order());
        Llm::assertPurpose('embed', 2);
    }

    public function test_assertions_fail_loudly(): void
    {
        Llm::fake();
        $this->expectException(AssertionFailedError::class);
        Llm::assertSent();
    }
}
