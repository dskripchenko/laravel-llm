<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Feature;

use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Models\Call;
use Dskripchenko\Llm\Purpose;
use Dskripchenko\Llm\Tests\TestCase;
use Dskripchenko\Llm\Transport\Attribution;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class AttributionTest extends TestCase
{
    public function test_a_gateway_gets_whose_the_call_is_as_headers_and_the_log_keeps_it(): void
    {
        $this->route(Purpose::Summarize, $this->provider('gateway', 'http://gateway.test/v1', ['api_key' => 'gw-key', 'attribution' => true]), 'small-model');
        Http::fake(['*' => Http::response(self::completion('Summary', 1000, 500))]);

        Llm::request(Purpose::Summarize)->onBehalfOf('user-42')->attribute(project: 'KB', task: 'KB-12', run: 'run-7')->user('Summarize')->run();

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer gw-key')
            && $request->hasHeader('X-On-Behalf-Of', 'user-42')
            && $request->hasHeader('X-Project', 'KB')
            && $request->hasHeader('X-Task', 'KB-12')
            && $request->hasHeader('X-Run', 'run-7')
            && ! str_contains((string) json_encode($request->data()), 'user-42'));
        $call = Call::query()->sole();
        $this->assertSame('user-42', $call->on_behalf_of);
        $this->assertSame(['project' => 'KB', 'task' => 'KB-12', 'run' => 'run-7'], $call->attribution);
        $this->assertNull($call->cost);
    }

    public function test_any_other_provider_never_gets_the_headers_but_the_log_keeps_them(): void
    {
        $this->route(Purpose::Summarize, $this->provider('vendor', 'http://vendor.test/v1', ['is_cloud' => true, 'cloud_enabled' => true]), 'small-model');
        Http::fake(['*' => Http::response(self::completion('Summary'))]);

        Llm::request(Purpose::Summarize)->onBehalfOf('user-42')->user('Summarize')->run();

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-On-Behalf-Of') && ! $request->hasHeader('X-Project'));
        $this->assertSame('user-42', Call::query()->sole()->on_behalf_of);
    }

    public function test_header_names_come_from_the_config_and_an_empty_one_is_not_sent(): void
    {
        config(['llm.attribution.headers.on_behalf_of' => 'X-Acting-For', 'llm.attribution.headers.run' => '']);
        $this->route(Purpose::Embed, $this->provider('gateway', 'http://gateway.test/v1', ['attribution' => true]), 'embedder');
        Http::fake(['*' => Http::response(['data' => [['index' => 0, 'embedding' => [0.1, 0.2]]], 'usage' => ['prompt_tokens' => 3]])]);

        Llm::embed('text', attribution: new Attribution(onBehalfOf: "agent\n7", run: 'run-1'));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Acting-For', 'agent 7') && ! $request->hasHeader('X-Run') && ! $request->hasHeader('X-On-Behalf-Of'));
        $this->assertSame(['run' => 'run-1'], Call::query()->sole()->attribution);
    }

    public function test_cost_comes_from_the_answer_else_from_the_provider_prices(): void
    {
        $priced = $this->provider('priced', 'http://priced.test/v1', ['prices' => ['small-model' => ['input' => 3, 'output' => 15], '*' => ['input' => 1, 'output' => 1]]]);
        $this->route(Purpose::Summarize, $priced, 'small-model');
        $this->route(Purpose::Extract, $priced, 'other-model');
        $answer = self::completion('Summary', 1000, 500);
        $answer['usage']['cost'] = 0.0123;
        Http::fakeSequence()->push(self::completion('Summary', 1000, 500))->push(self::completion('{}', 2000, 1000))->push($answer);

        Llm::request(Purpose::Summarize)->user('a')->run();
        Llm::request(Purpose::Extract)->user('b')->run();
        Llm::request(Purpose::Summarize)->user('c')->run();

        $costs = Call::query()->orderBy('id')->pluck('cost')->all();
        $this->assertEqualsWithDelta(0.0105, $costs[0], 1e-9);
        $this->assertEqualsWithDelta(0.003, $costs[1], 1e-9);
        $this->assertEqualsWithDelta(0.0123, $costs[2], 1e-9);
    }

    public function test_nothing_set_nothing_sent_and_no_cost_without_prices(): void
    {
        $this->route(Purpose::Summarize, $this->provider('gateway', 'http://gateway.test/v1', ['attribution' => true]), 'small-model');
        Http::fake(['*' => Http::response(self::completion('Summary'))]);

        Llm::request(Purpose::Summarize)->user('Summarize')->run();

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-On-Behalf-Of'));
        $call = Call::query()->sole();
        $this->assertSame([null, null, null], [$call->on_behalf_of, $call->attribution, $call->cost]);
    }
}
