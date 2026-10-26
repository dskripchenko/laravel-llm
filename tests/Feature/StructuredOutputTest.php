<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Feature;

use Dskripchenko\Llm\CallStatus;
use Dskripchenko\Llm\Exceptions\InvalidStructuredOutput;
use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Models\Call;
use Dskripchenko\Llm\Purpose;
use Dskripchenko\Llm\Tests\Fixtures\ClassifyIssue;
use Dskripchenko\Llm\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class StructuredOutputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->route(Purpose::Classify, $this->provider('local', 'http://local.test/v1'), 'qwen2.5:7b');
    }

    public function test_an_invalid_answer_is_retried_with_the_errors_fed_back(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(self::completion('{"type":"chore","priority":"high"}', 50, 8))
            ->push(self::completion("```json\n{\"type\":\"bug\",\"priority\":2}\n```", 80, 9))]);

        $result = Llm::prompt(ClassifyIssue::class, ['body' => 'It crashes'])->run();

        $this->assertSame(['type' => 'bug', 'priority' => 2], $result->data);
        $this->assertSame(2, $result->attempts);
        $this->assertSame(50 + 8 + 80 + 9, $result->usage->total());

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);
        /** @var Request $retry */
        $retry = $recorded[1][0];
        $messages = $retry->data()['messages'];
        $this->assertSame('assistant', $messages[2]['role']);
        $this->assertSame('{"type":"chore","priority":"high"}', $messages[2]['content']);
        $this->assertStringContainsString('$.type: must be one of "bug", "feature", "question", got "chore"', $messages[3]['content']);
        $this->assertStringContainsString('$.priority: expected integer, got string', $messages[3]['content']);
        $this->assertStringContainsString('"required":["type","priority"]', $messages[0]['content']);

        $this->assertSame([CallStatus::InvalidOutput, CallStatus::Ok], Call::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame([1, 2], Call::query()->orderBy('id')->pluck('attempt')->all());
        $this->assertSame(['type' => 'bug', 'priority' => 2], Call::query()->where('status', 'ok')->sole()->result['data'] ?? null);
    }

    public function test_gives_up_after_the_configured_attempts(): void
    {
        config(['llm.structured.max_attempts' => 2]);
        Http::fake(['*' => Http::response(self::completion('I think it is a bug.'))]);

        try {
            Llm::request(Purpose::Classify)
                ->user('Classify')
                ->data('issue', 'It crashes')
                ->schema(['type' => 'object', 'required' => ['type']], 'triage')
                ->run();
            $this->fail('expected InvalidStructuredOutput');
        } catch (InvalidStructuredOutput $e) {
            $this->assertSame(2, $e->attempts);
            $this->assertSame('I think it is a bug.', $e->lastOutput);
            $this->assertStringContainsString('not valid JSON', $e->errors[0]);
        }
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->data()['response_format']['json_schema']['name'] === 'triage');
        $this->assertSame(2, Call::query()->where('status', CallStatus::InvalidOutput->value)->count());
    }

    public function test_free_text_is_not_validated_or_retried(): void
    {
        Http::fake(['*' => Http::response(self::completion('not json'))]);

        $this->assertSame('not json', Llm::request(Purpose::Classify)->user('x')->run()->text);
        Http::assertSent(fn (Request $r) => ! isset($r->data()['response_format']));
        Http::assertSentCount(1);
    }
}
