# dskripchenko/laravel-llm

> 🌐 [English](../../README.md) · [Русский](../ru/README.md) · [Deutsch](../de/README.md) · **中文**

通过 Ollama、vLLM、LM Studio 和 llama.cpp server 都支持的 OpenAI 兼容 API，在
Laravel 中使用本地 LLM——无需 SDK，只用 Laravel 的 Http 客户端。调用方请求的是一个
**用途**（`summarize`、`classify`……），而不是某个模型：提供方和路由存放在数据库中，
提供方不可用时会走备用路由。提示词是代码中的预设，租户可以用带版本的覆盖来替换；结构化
回答会依据 JSON schema 校验，校验失败时把错误反馈给模型并重试；用户文本始终被标记为数据。
每次调用都会连同令牌数和耗时一起记录到日志，请求/响应体在 30 天后清理，并且在调用模型之前
检查每日令牌配额。process-engine 效果（effect）可在长时间运行的流程中执行调用，而
`Llm::fake()` 让这一切都可测试。

## 安装

```bash
composer require dskripchenko/laravel-llm
php artisan migrate
```

PHP 8.2–8.5，Laravel 11、12 或 13。数据表（`llm_providers`、`llm_routes`、
`llm_prompt_templates`、`llm_calls`）位于默认连接上——如果每个租户一个 schema，请设置
`LLM_RUN_MIGRATIONS=false`，将迁移发布（`php artisan vendor:publish --tag=llm-migrations`）
到租户迁移路径，并为每个租户分别执行。

## 配置提供方和路由

```php
use Dskripchenko\Llm\Models\Provider;
use Dskripchenko\Llm\Models\Route;

$gpu = Provider::create(['name' => 'gpu-box', 'base_url' => 'http://10.0.0.5:8000/v1', 'limits' => ['timeout' => 180]]);
$cpu = Provider::create(['name' => 'ollama',  'base_url' => 'http://127.0.0.1:11434/v1']);

$fallback = Route::create(['purpose' => 'summarize', 'provider_id' => $cpu->id, 'model' => 'llama3.2:3b']);
Route::create(['purpose' => 'summarize', 'provider_id' => $gpu->id, 'model' => 'qwen2.5:32b',
               'fallback_route_id' => $fallback->id, 'options' => ['temperature' => 0.2]]);
```

超时、连接错误、5xx 或 429 会转到备用路由；4xx 则不会。**云端提供方（`is_cloud`）在管理员
设置 `cloud_enabled` 之前永远不会被使用**——提示词中包含租户数据。

## 调用模型

```php
use Dskripchenko\Llm\Facades\Llm;

// 使用预设（见 docs/zh/prompts-and-schemas.md）
$result = Llm::prompt(ClassifyIssue::class, ['body' => $issue->description])
    ->about($issue)              // 作为调用的主体记录到日志
    ->run();
$result->data;                   // ['type' => 'bug', 'priority' => 2]，已校验

// 手动构建
$result = Llm::request('summarize')
    ->system('Summarize the issue in two sentences.')
    ->user('Issue:')
    ->data('issue', $issue->description)     // 不可信文本 → 数据块
    ->stream(function (string $delta): void { echo $delta; });

Llm::embed(['first text', 'second text'])->vectors;
Llm::rerank('query', $documents)->order();
```

## 测试

```php
Llm::fake(['issue.classify' => ['type' => 'bug', 'priority' => 2], 'summarize' => 'Short.']);

// … 运行你的代码 …

Llm::assertPurpose('classify', 1);
Llm::assertSent(fn ($call) => $call->contains('It crashes', 'user'));
```

## 文档

- [提供方与路由](providers-and-routes.md)
- [提示词、schema 与用户数据](prompts-and-schemas.md)
- [调用日志与配额](logging-and-quotas.md)
- [process-engine 效果 `llm.run`](process-engine.md)
- [测试](testing.md)

## 测试本包

```bash
composer test      # PHPUnit + Testbench，内存 SQLite，无网络
composer analyse   # PHPStan，max 级别
composer format    # Pint
```

## 许可证

MIT。见 [LICENSE](../../LICENSE)。
