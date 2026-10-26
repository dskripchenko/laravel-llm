<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Unit;

use Dskripchenko\Llm\Exceptions\TransportFailure;
use Dskripchenko\Llm\Transport\SseParser;
use PHPUnit\Framework\TestCase;

final class SseParserTest extends TestCase
{
    public function test_folds_content_tool_calls_and_usage_across_split_chunks(): void
    {
        $deltas = [];
        $parser = new SseParser(function (string $d) use (&$deltas): void {
            $deltas[] = $d;
        });
        $stream = implode('', [
            ": keep-alive\n\n",
            'data: {"choices":[{"index":0,"delta":{"role":"assistant","content":"Hel"}}]}'."\r\n\r\n",
            'data: {"choices":[{"index":0,"delta":{"content":"lo"}}]}'."\n\n",
            'data: {"choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"id":"c1","function":{"name":"set_label","arguments":"{\"la"}}]}}]}'."\n\n",
            'data: {"choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"function":{"arguments":"bel\":\"bug\"}"}}]},"finish_reason":"tool_calls"}]}'."\n\n",
            'data: {"choices":[],"usage":{"prompt_tokens":12,"completion_tokens":7}}'."\n\n",
            "data: [DONE]\n\n",
            'data: {"choices":[{"delta":{"content":"ignored"}}]}'."\n",
        ]);
        foreach (str_split($stream, 7) as $piece) {
            $parser->push($piece);
        }
        $response = $parser->finish();

        $this->assertSame(['Hel', 'lo'], $deltas);
        $this->assertSame('Hello', $response->content);
        $this->assertSame('tool_calls', $response->finishReason);
        $this->assertSame(19, $response->usage->total());
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('set_label', $response->toolCalls[0]->name);
        $this->assertSame(['label' => 'bug'], $response->toolCalls[0]->arguments);
    }

    public function test_an_error_event_is_a_transient_failure(): void
    {
        $parser = new SseParser(fn () => null);
        $this->expectException(TransportFailure::class);
        $parser->push('data: {"error":{"message":"model unloaded"}}'."\n");
    }
}
