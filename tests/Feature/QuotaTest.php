<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Feature;

use Dskripchenko\Llm\Exceptions\QuotaExceeded;
use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Models\Call;
use Dskripchenko\Llm\Purpose;
use Dskripchenko\Llm\Quota\QuotaScope;
use Dskripchenko\Llm\Tests\TestCase;
use Dskripchenko\Llm\Transport\CallMeta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

final class QuotaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->route(Purpose::Chat, $this->provider('local', 'http://local.test/v1'), 'm');
        Http::fake([
            'emb.test/*' => Http::response(['data' => [['index' => 0, 'embedding' => [1]]], 'usage' => ['prompt_tokens' => 500]]),
            '*' => Http::response(self::completion('ok', 60, 40)),
        ]);
        config(['llm.quota.tenant_tokens_per_day' => 250]);
        Llm::resolveQuotaUsing(fn (CallMeta $meta, ?string $type, int|string|null $id) => new QuotaScope(
            tenantKey: 'acme',
            projectKey: $type === 'project' ? 'project:'.$id : null,
            projectLimit: 150,
        ));
    }

    public function test_the_tenant_quota_is_checked_before_calling_the_model(): void
    {
        Llm::request()->user('1')->run();   // 100
        Llm::request()->user('2')->run();   // 200
        Llm::request()->user('3')->run();   // 300: allowed, 200 < 250 when it started

        try {
            Llm::request()->user('4')->run();
            $this->fail('expected QuotaExceeded');
        } catch (QuotaExceeded $e) {
            $this->assertSame(['tenant', 'acme', 250, 300], [$e->scope, $e->key, $e->limit, $e->used]);
            $this->assertSame('quota_exceeded', $e->errorCode());
        }
        Http::assertSentCount(3);
        $this->assertSame(['acme'], Call::query()->distinct()->pluck('tenant_key')->all());
    }

    public function test_the_project_quota_counts_only_its_project_and_resets_daily(): void
    {
        Llm::request()->aboutRef('project', 7)->user('a')->run();
        Llm::request()->aboutRef('project', 7)->user('b')->run();

        try {
            Llm::request()->aboutRef('project', 7)->user('c')->run();
            $this->fail('expected QuotaExceeded');
        } catch (QuotaExceeded $e) {
            $this->assertSame(['project', 'project:7', 150, 200], [$e->scope, $e->key, $e->limit, $e->used]);
        }
        $this->assertSame('ok', Llm::request()->aboutRef('project', 8)->user('d')->run()->text);

        Carbon::setTestNow(Carbon::now()->addDay());
        $this->assertSame('ok', Llm::request()->aboutRef('project', 7)->user('e')->run()->text);
        Carbon::setTestNow();
    }

    public function test_an_explicit_scope_overrides_the_resolver_and_embeddings_count_too(): void
    {
        $this->route(Purpose::Embed, $this->provider('emb', 'http://emb.test/v1'), 'e');

        Llm::embed('text', quota: new QuotaScope(tenantKey: 'other', tenantLimit: 400));

        $this->expectException(QuotaExceeded::class);
        Llm::request()->user('x')->quota(new QuotaScope(tenantKey: 'other', tenantLimit: 400))->run();
    }
}
