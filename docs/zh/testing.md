# 测试

## `Llm::fake()`

`Llm::fake()` 替换 HTTP 传输层。其上的一切都真实运行——路由与备用、配额、提示词渲染、schema
校验与重试、调用日志——因此宿主应用的测试看到的行为与生产环境相同。若某个用途没有配置路由，
调用会发往合成的本地提供方 `fake` / 模型 `fake-model`，所以测试不需要任何提供方记录。

```php
use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Testing\FakeResponse;
use Dskripchenko\Llm\Testing\RecordedCall;

// 列表：按顺序给出回答，适用于任何调用。用完后抛出异常。
Llm::fake(['first answer', ['type' => 'guide', 'level' => 2]]);

// 映射：依次按场景、提示词键、用途匹配。值会被重复使用……
Llm::fake([
    'article.classify' => ['type' => 'guide', 'level' => 2],
    'summarize' => 'Short.',
    'wiki.answer' => fn (RecordedCall $call) => 'You asked: '.$call->text('user'),
    // ……除非它是一个序列
    'article.title' => Llm::sequence(['first', 'second'], whenEmpty: 'later'),
]);
// 未匹配的调用得到空文本（embed 得到确定性的向量，
// rerank 得到原始顺序）。
```

| 回答可以是 | |
|---|---|
| 字符串 | 文本回答 |
| 数组 | 结构化回答（先编码为 JSON，再依据 schema 校验） |
| `FakeResponse::text($s, $usage)`、`::json($a)` | 带显式用量 |
| `FakeResponse::toolCall($name, $args)` | 工具调用 |
| `FakeResponse::embeddings($vectors)`、`::ranking($list)` | |
| `FakeResponse::unavailable(503)` | 暂时性失败：尝试下一条路由 |
| `FakeResponse::rejected(400)` | 4xx |
| Throwable | 被抛出 |
| Closure | 接收 `RecordedCall`，返回以上任意一种 |

先给出无效的结构化回答、再给出有效的回答，即可触发真实的重试逻辑。除非显式给出，令牌用量按
估算得出（每四个字符一个令牌），因此配额也能正常工作。

## 断言

```php
Llm::assertSentCount(2);
Llm::assertNothingSent();
Llm::assertPurpose('classify', 1);
Llm::assertScenario('article.classify');
Llm::assertSent(fn (RecordedCall $c) => $c->contains('To reset a password, open Settings', 'user') && $c->model() === 'fake-model');
Llm::assertNotSent(fn (RecordedCall $c) => $c->purpose()->value === 'embed');
Llm::recorded();   // list<RecordedCall>: type, provider, request, purpose(), scenario(), promptKey(), messages(), text($role)
```

`Llm::fake()` 还会返回具有相同方法的 `FakeTransport`。

## 在 HTTP 层测试

要测试传输层本身，请使用 `Http::fake()` 并提供 OpenAI 格式的响应体；流式回答是一个
`text/event-stream` 响应体，由若干 `data: {...}` 行组成，并以 `data: [DONE]` 结尾。

## 流程

当效果从 Laravel 容器中解析时，`llm.run` 效果可以配合 LLM fake 在引擎的 `ProcessTest`
上运行：

```php
$test = new ProcessTest(container: new class(app()) implements \Dskripchenko\ProcessEngine\Runtime\Container {
    public function __construct(private $app) {}
    public function get(string $class): object { return $this->app->make($class); }
});

Llm::fake(['article.classify' => ['type' => 'guide', 'level' => 2]]);
$test->begin(ArticleReview::class, [...])->send('article.published', [...])->assertIn('suggested');
```

或者用 `->fake(RunLlm::class, returns: Send::to(...))` 把整个效果替换掉。

## 真实环境冒烟测试

本包自身的测试套件中有一个需显式启用的、针对本地 Ollama 的测试。它只在设置了
`LLM_OLLAMA_URL`（例如 `http://127.0.0.1:11434`）时运行，使用已拉取的最小聊天模型；当
Ollama 未运行或没有聊天模型时会被跳过。其他所有测试都不需要网络：任何意外的 HTTP 请求都会
使测试套件失败。

```bash
LLM_OLLAMA_URL=http://127.0.0.1:11434 vendor/bin/phpunit --group live
```
