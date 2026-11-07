<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Feature;

use Dskripchenko\Llm\CallStatus;
use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Models\Call;
use Dskripchenko\Llm\Purpose;
use Dskripchenko\Llm\Tests\Fixtures\Article;
use Dskripchenko\Llm\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

final class LoggingTest extends TestCase
{
    public function test_logs_metrics_bodies_and_the_subject(): void
    {
        Schema::create('articles', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->timestamps();
        });
        $article = Article::query()->create(['title' => 'Reset a password']);
        $this->route(Purpose::Summarize, $this->provider('local', 'http://local.test/v1'), 'qwen2.5:7b');
        Http::fake(['*' => Http::response(self::completion('Summary', 12, 3))]);

        $result = Llm::request(Purpose::Summarize)->scenario('article.digest')->about($article)->user('Summarize')->data('body', 'secret body')->run();

        $call = Call::query()->sole();
        $this->assertSame($result->callId, $call->id);
        $this->assertSame(['article.digest', Purpose::Summarize, 'local', 'qwen2.5:7b', 12, 3, 15, CallStatus::Ok],
            [$call->scenario, $call->purpose, $call->provider, $call->model, $call->prompt_tokens, $call->completion_tokens, $call->total_tokens, $call->status]);
        $this->assertTrue($call->subject?->is($article));
        $this->assertStringContainsString('secret body', (string) $call->request);
        $this->assertStringContainsString('Summary', (string) $call->response);
        $this->assertSame('Summary', $call->result['text'] ?? null);
        $this->assertGreaterThanOrEqual(0, $call->duration_ms);
    }

    public function test_prune_keeps_metrics_and_drops_old_bodies(): void
    {
        Llm::fake(['old', 'new']);
        Carbon::setTestNow(Carbon::now()->subDays(31));
        Llm::request()->user('old prompt')->run();
        Carbon::setTestNow();
        Llm::request()->user('new prompt')->run();

        $this->artisan('llm:prune')->expectsOutputToContain('Pruned the bodies of 1 LLM call(s).')->assertSuccessful();

        [$old, $new] = Call::query()->orderBy('id')->get()->all();
        $this->assertNull($old->request);
        $this->assertNull($old->response);
        $this->assertNull($old->result);
        $this->assertNotNull($old->bodies_pruned_at);
        $this->assertGreaterThan(0, $old->total_tokens);
        $this->assertSame('fake-model', $old->model);
        $this->assertStringContainsString('new prompt', (string) $new->request);

        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->assertSame(1, Llm::prune(0));
        $this->assertSame(0, Llm::prune(0));
        Carbon::setTestNow();
    }

    public function test_prune_is_scheduled_daily(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'llm:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }

    public function test_logging_can_be_disabled(): void
    {
        config(['llm.log.enabled' => false]);
        Llm::fake(['x']);

        $this->assertNull(Llm::request()->user('x')->run()->callId);
        $this->assertSame(0, Call::query()->count());
    }
}
