<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Feature;

use DateTimeImmutable;
use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Mode;
use Dskripchenko\Llm\Models\Call;
use Dskripchenko\Llm\ProcessEngine\LlmRun;
use Dskripchenko\Llm\ProcessEngine\RunLlm;
use Dskripchenko\Llm\Purpose;
use Dskripchenko\Llm\Testing\FakeResponse;
use Dskripchenko\Llm\Tests\Fixtures\ClassifyArticle;
use Dskripchenko\Llm\Tests\Fixtures\ReviewProcess;
use Dskripchenko\Llm\Tests\TestCase;
use Dskripchenko\ProcessEngine\Attributes\Idempotent;
use Dskripchenko\ProcessEngine\Effect\Effect;
use Dskripchenko\ProcessEngine\Effect\Send;
use Dskripchenko\ProcessEngine\Runtime\Container;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Illuminate\Contracts\Foundation\Application;
use ReflectionClass;

/**
 * The real engine (in-memory store, virtual clock) runs a process whose
 * effects are resolved from the Laravel container, so RunLlm itself runs
 * against the LLM fake and the database log.
 */
final class RunLlmEffectTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('llm.prompts.presets', [ClassifyArticle::class]);
    }

    private function engine(): ProcessTest
    {
        $app = $this->app;
        assert($app instanceof Application);

        return new ProcessTest(container: new class($app) implements Container
        {
            public function __construct(private readonly Application $app) {}

            public function get(string $class): object
            {
                return $this->app->make($class);
            }
        });
    }

    public function test_declares_idempotent_retry_semantics(): void
    {
        $attributes = (new ReflectionClass(RunLlm::class))->getAttributes(Idempotent::class);
        $this->assertCount(1, $attributes);
        $this->assertSame(3, $attributes[0]->newInstance()->retry()->attempts);
        $this->assertSame(3, config('llm.effect.attempts'));
    }

    public function test_the_process_receives_llm_done_with_the_result(): void
    {
        Llm::fake(['article.classify' => ['type' => 'guide', 'level' => 2]]);

        $t = $this->engine()->begin(ReviewProcess::class, ['articleId' => 42, 'body' => 'To reset a password, open Settings']);
        $t->send('classify')
            ->assertIn('review')
            ->assertContext('suggestion.type', 'guide')
            ->assertContext('suggestion.level', 2)
            ->assertContext('mode', 'suggest')
            ->assertContext('tag', 'review')
            ->assertNoIncidents();

        $call = Call::query()->sole();
        $this->assertSame(['article', '42'], [$call->subject_type, (string) $call->subject_id]);
        $this->assertStringStartsWith($t->id().'/step', (string) $call->idempotency_key);
        Llm::assertSentCount(1);
    }

    public function test_the_request_can_come_from_the_event_payload(): void
    {
        Llm::fake(['summarize' => 'Short.']);

        $this->engine()->begin(ReviewProcess::class, ['articleId' => 1, 'body' => 'b'])
            ->send('ask', LlmRun::ask(Purpose::Summarize, 'Summarize the article', 'b')->mode(Mode::Apply)->toArray())
            ->assertIn('answered')
            ->assertContext('answer', 'Short.')
            ->assertContext('mode', 'apply');
    }

    public function test_a_non_transient_failure_is_sent_as_llm_failed(): void
    {
        Llm::fake(['article.classify' => 'definitely not json']);

        $this->engine()->begin(ReviewProcess::class, ['articleId' => 42, 'body' => 'b'])
            ->send('classify')
            ->assertIn('manual')
            ->assertContext('error', 'invalid_output')
            ->assertNoIncidents();
        Llm::assertSentCount(3);
    }

    public function test_a_transient_failure_is_retried_by_the_engine_then_reported(): void
    {
        $fake = Llm::fake([FakeResponse::unavailable(), FakeResponse::unavailable(), ['type' => 'reference', 'level' => 1]]);

        $t = $this->engine()->begin(ReviewProcess::class, ['articleId' => 42, 'body' => 'b'])->send('classify');
        $t->assertIn('classifying');
        $t->travel('1 hour', step: '1 minute')
            ->assertIn('review')
            ->assertContext('suggestion.type', 'reference');
        $this->assertCount(3, $fake->recorded());

        Llm::fake(array_fill(0, 3, FakeResponse::unavailable()));
        $this->engine()->begin(ReviewProcess::class, ['articleId' => 43, 'body' => 'b'])
            ->send('classify')
            ->travel('1 hour', step: '1 minute')
            ->assertIn('manual')
            ->assertContext('error', 'unavailable')
            ->assertNoIncidents();
    }

    public function test_a_repeated_attempt_with_the_same_key_replays_instead_of_calling_again(): void
    {
        Llm::fake([['type' => 'guide', 'level' => 4]]);
        $handler = $this->app->make(RunLlm::class);
        $fx = fn (int $attempt) => new Effect(key: 'inst-1/step3/review.entry.RunLlm#0', instanceId: 'inst-1', attempt: $attempt, now: new DateTimeImmutable);
        $request = ['llm' => LlmRun::prompt('article.classify', ['body' => 'x'])->tag('t1')->toArray()];

        $first = $handler($fx(1), $request);
        $second = $handler($fx(2), $request);

        $this->assertInstanceOf(Send::class, $first);
        $this->assertSame([RunLlm::DONE, RunLlm::DONE], [$first->event, $second->event]);
        $this->assertSame($first->payload['data'], $second->payload['data']);
        $this->assertFalse($first->payload['replayed']);
        $this->assertTrue($second->payload['replayed']);
        $this->assertSame('t1', $second->payload['tag']);
        Llm::assertSentCount(1);
    }

    public function test_a_malformed_request_fails_without_a_call(): void
    {
        Llm::fake();
        $send = $this->app->make(RunLlm::class)(new Effect('k', 'i', 1, new DateTimeImmutable), ['llm' => ['mode' => 'apply']]);

        $this->assertSame(RunLlm::FAILED, $send->event);
        $this->assertSame('invalid_request', $send->payload['code']);
        Llm::assertNothingSent();
    }
}
