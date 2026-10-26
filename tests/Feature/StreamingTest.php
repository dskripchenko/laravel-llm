<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Feature;

use Dskripchenko\Llm\CallStatus;
use Dskripchenko\Llm\Exceptions\ProviderUnavailable;
use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Models\Call;
use Dskripchenko\Llm\Purpose;
use Dskripchenko\Llm\Tests\TestCase;
use Illuminate\Support\Facades\Http;

final class StreamingTest extends TestCase
{
    private static function sse(string ...$deltas): string
    {
        $out = '';
        foreach ($deltas as $delta) {
            $out .= 'data: '.json_encode(['choices' => [['delta' => ['content' => $delta]]]])."\n\n";
        }

        return $out.'data: {"choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":9,"completion_tokens":3}}'."\n\ndata: [DONE]\n\n";
    }

    public function test_streams_deltas_falls_back_before_the_first_one_and_logs_the_whole_answer(): void
    {
        Http::fake([
            'gpu.local/*' => Http::response('busy', 503),
            'cpu.local/*' => Http::response(self::sse('Sprint ', 'is ', 'late.'), 200, ['Content-Type' => 'text/event-stream']),
        ]);
        $fallback = $this->route(Purpose::Chat, $this->provider('cpu', 'http://cpu.local/v1'), 'small');
        $this->route(Purpose::Chat, $this->provider('gpu', 'http://gpu.local/v1'), 'big', $fallback);

        $deltas = [];
        $result = Llm::request()->user('Status?')->stream(function (string $d) use (&$deltas): void {
            $deltas[] = $d;
        });

        $this->assertSame(['Sprint ', 'is ', 'late.'], $deltas);
        $this->assertSame('Sprint is late.', $result->text);
        $this->assertSame('cpu', $result->provider);
        $this->assertSame(12, $result->usage->total());
        $ok = Call::query()->where('status', CallStatus::Ok->value)->sole();
        $this->assertSame(12, $ok->total_tokens);
        $this->assertStringContainsString('"stream":true', (string) $ok->request);
    }

    public function test_a_stream_that_breaks_after_a_delta_does_not_fall_back(): void
    {
        $broken = 'data: {"choices":[{"delta":{"content":"Half"}}]}'."\n\n".'data: {"error":{"message":"model crashed"}}'."\n\n";
        Http::fake([
            'a.local/*' => Http::response($broken, 200, ['Content-Type' => 'text/event-stream']),
            'b.local/*' => Http::response(self::sse('other'), 200),
        ]);
        $fallback = $this->route(Purpose::Chat, $this->provider('b', 'http://b.local/v1'), 'm');
        $this->route(Purpose::Chat, $this->provider('a', 'http://a.local/v1'), 'm', $fallback);

        $deltas = [];
        try {
            Llm::request()->user('x')->stream(function (string $d) use (&$deltas): void {
                $deltas[] = $d;
            });
            $this->fail('expected ProviderUnavailable');
        } catch (ProviderUnavailable $e) {
            $this->assertStringContainsString('model crashed', $e->getMessage());
        }
        $this->assertSame(['Half'], $deltas);
        Http::assertSentCount(1);
    }
}
