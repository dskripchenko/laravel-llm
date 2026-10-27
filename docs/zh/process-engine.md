# process-engine 效果 `llm.run`

借助 [dskripchenko/process-engine](https://github.com/dskripchenko/process-engine)
（及其 Laravel 桥接包 `dskripchenko/laravel-process-engine`），流程可以把模型调用作为一个
持久化效果（durable effect）来执行。集成代码位于 `Dskripchenko\Llm\ProcessEngine`，只有在
使用时才会加载；引擎是建议依赖（suggest）。

```bash
composer require dskripchenko/laravel-process-engine
```

## 注册

除了使用该类之外无需任何注册：效果是一个由引擎从容器中解析的类。`RunLlm`（常量
`EFFECT = 'llm.run'`、`DONE = 'llm.done'`、`FAILED = 'llm.failed'`）声明了
`#[Idempotent(retry: new Retry(attempts: 3, initialInterval: '30 seconds', backoff: 4.0, maxInterval: '15 minutes', attemptTimeout: '10 minutes'))]`。

## 作为动作使用

```php
use Dskripchenko\Llm\Mode;
use Dskripchenko\Llm\ProcessEngine\LlmRun;
use Dskripchenko\Llm\ProcessEngine\RunLlm;

final class IssueTriage extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('new', fn (Builder $s) => $s
            ->on('issue.created', to: 'classifying', run: $this->prepare(...)));

        $p->state('classifying', fn (Builder $s) => $s
            ->entry(RunLlm::class)                                   // 读取 $context['llm']
            ->on(RunLlm::DONE, to: 'suggested', run: $this->keep(...))
            ->on(RunLlm::FAILED, to: 'manual'));
        // …
    }

    private function prepare(array &$context, array $event): void
    {
        $context['llm'] = LlmRun::prompt('issue.classify', ['body' => $event['description']])
            ->about('issue', $event['issueId'])
            ->mode(Mode::Suggest)
            ->tag('triage')
            ->toArray();
    }

    private function keep(array &$context, array $event): void
    {
        $context['suggestion'] = $event['data'];   // 已校验的结构化回答
    }
}
```

当触发事件的 payload 中含有 `prompt` 或 `user` 时，请求取自该 payload——只有转换的 `run:`
能看到事件：

```php
->on('summarize.requested', to: 'summarizing', run: RunLlm::class)
// payload: LlmRun::ask(Purpose::Summarize, 'Summarize the discussion.', $text)->toArray()
```

否则取自 `$context['llm']`（`entry()` 动作没有事件）。

请把它作为动作（`run:`、`entry()`）使用，而不是用 `invoke()`：结果以 `llm.done` /
`llm.failed` 事件的形式到达。（使用 `invoke()` 时这些事件仍会发送，但同时还会触发
`done.invoke.*`。）

## `LlmRun`——以数据形式表示的请求

| | |
|---|---|
| `LlmRun::prompt($key, $variables)` | 预设 |
| `LlmRun::ask(Purpose, $user, $data = null, $system = null)` | 手动构建；`$data` 会成为数据块 |
| `->scenario()`、`->about($type, $id)`、`->mode(Mode)`、`->tag()`、`->options([...])` | |
| `->toArray()` / `LlmRun::fromArray()` | |

## 事件

`llm.done`：`text`、`data`、`tool_calls`、`usage`、`provider`、`model`、
`call_id`、`attempts`、`finish_reason`、`replayed`、`mode`、`tag`、`key`。

`llm.failed`：`code`、`message`、`details`、`mode`、`tag`、`key`。错误码：
`unavailable`、`rejected`、`invalid_output`、`quota_exceeded`、
`cloud_not_enabled`、`no_route`、`invalid_prompt`、`invalid_request`。

## 重试与幂等

- 幂等键就是引擎的效果键——实例 + 步骤 + 动作（`{instance}/step{n}/{action}#{i}`）。处理器
  把它传给调用；在同一键下记录过的成功调用会从日志中**重放**（`replayed: true`），而不是再次
  调用模型，例如 worker 在模型已回答、但事件尚未保存时崩溃的情况。
- 暂时性失败（所有提供方都不可用）会抛出异常，由引擎按策略重试。在最后一次尝试时
  （`llm.effect.attempts`，请保持与策略的尝试次数一致）改为发送错误码为 `unavailable` 的
  `llm.failed`。
- 其他所有失败都会立即以 `llm.failed` 发送——重试也不会成功。
- 其他异常（例如数据库故障）会作为不确定的失败传递给引擎并被重试。

## 人机协同：`Mode`

`Dskripchenko\Llm\Mode` 是流程随请求携带、并在 `llm.done.mode` 中收回的数据。本包不会对它
做任何其他处理；具体流程由宿主流程和 UI 实现。

| 模式 | 预期流程 |
|---|---|
| `apply` | 流程立即应用回答（由 `on(llm.done)` 写入）。适用于低风险、可撤销、可见的更改；请将其记录到历史中，以便人可以撤销。 |
| `suggest`（默认） | 流程把回答作为建议保存在主体旁边并继续执行；人可以随时接受、编辑或忽略它。没有人的参与什么都不会改变。 |
| `approve` | 流程在某个状态中等待（`on('suggestion.approved')`、`on('suggestion.rejected')`，也许还有 `after('3 days')`），直到人做出决定；只有批准才会应用回答。 |

`Mode::appliesAutomatically()` 和 `Mode::waitsForHuman()` 便于据此分支。在任何模式下，
模型的回答都是不可信输入——请像校验人的输入一样校验它。
