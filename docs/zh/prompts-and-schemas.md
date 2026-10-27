# 提示词、schema 与用户数据

## 代码中的预设

```php
use Dskripchenko\Llm\Prompts\Prompt;
use Dskripchenko\Llm\Purpose;

final class ClassifyIssue extends Prompt
{
    public function key(): string { return 'issue.classify'; }

    public function purpose(): Purpose { return Purpose::Classify; }

    public function system(): string
    {
        return 'You triage issues of project {{ project }}.';
    }

    public function user(): string
    {
        return "Labels in use:\n{{ labels }}\n\nThe issue:\n{{ body }}";
    }

    public function variables(): array
    {
        return ['project' => 'string', 'labels' => '?list', 'body' => 'data'];
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['bug', 'feature', 'question']],
                'priority' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
            ],
            'required' => ['type', 'priority'],
            'additionalProperties' => false,
        ];
    }

    public function options(): array { return ['temperature' => 0]; }
}
```

在 `llm.prompts.presets` 中注册预设（或使用 `Llm::prompts()->register()`；传给
`Llm::prompt()` 的预设类会自行注册）。模板在注册预设时接受检查：每个 `{{ placeholder }}`
都必须是已声明的变量。

### 变量是带类型的上下文

| 类型 | 接受 | 渲染为 |
|---|---|---|
| `string` | string、Stringable | 原样——**由宿主编写的可信文本** |
| `int`、`float`、`bool` | | 其字符串形式 |
| `list` | 列表 | 每个元素一行 `- item` |
| `json` | 任意值 | JSON |
| `data` | string、Stringable | 数据块（见下文） |

`?type` 表示变量可选。缺失的变量、类型错误的变量或预设未声明的变量，都会在发送任何内容之前
抛出 `InvalidPrompt`（一次性列出所有问题）。

## 用户文本是数据，不是指令

用户写下的任何内容——问题描述、评论、页面——都必须是 `data` 变量，或在手动构建的请求中通过
`->data($name, $text)` 传入。它会被包裹为

```
<<<DATA body 3f9a0c2d7e11>>>
…the text…
<<<END DATA 3f9a0c2d7e11>>>
```

其中边界标记为每个请求随机生成（文本无法闭合自己的块，文本中出现的边界标记都会被移除），同时
system 消息中会加入一条指令：数据块是要处理的不可信材料，绝不是指令，绝不改变任务或回答格式。
这让提示词注入更困难，但并不能使其不可能。请把模型的回答当作不可信输入，对任何会修改数据的
操作使用 `suggest` / `approve` 模式（见 [process-engine](process-engine.md)）。

## 租户的带版本覆盖

```php
$prompts = Llm::prompts();

$prompts->override('issue.classify', user: "{{ body }}", system: 'Be strict.', note: 'stricter');  // v1，生效中
$prompts->override('issue.classify', user: "…", activate: false);   // v2，草稿
$prompts->activate('issue.classify', 2);                            // v2 生效
$prompts->revert('issue.classify');                                  // 回到代码中的预设
$prompts->versions('issue.classify');                                // 历史
```

覆盖会替换 `system`、`user`，以及（如果提供的话）`schema`；变量、用途和选项仍沿用预设的。
使用未声明占位符的覆盖会被拒绝。版本号只增不减；每次调用都会记录生效的版本（`prompt_version`，
使用预设时为 null）。

## 结构化回答

预设的 `schema()` 或 `->schema($schema, $name)` 要求返回 JSON：

1. schema 以 `response_format`（`json_schema`，`strict`）的形式发送给提供方，并且为了照顾
   忽略该参数的模型，也会写入 system 消息；
2. 解析回答（允许 Markdown 代码围栏、开头的 `<think>` 块或包围单个对象的散文）并进行校验；
3. 无效回答会以 `invalid_output` 记录到日志，然后把模型的回答和错误附加上去再次询问模型——
   总尝试次数最多为 `llm.structured.max_attempts`（3）；
4. 之后抛出 `InvalidStructuredOutput`（`errors`、`lastOutput`、`attempts`）。

`LlmResult::$data` 以数组形式保存校验后的值；`$attempts` 和 `$usage` 涵盖所有尝试。

### 支持的 schema 子集

`type`（单个或列表：`object`、`array`、`string`、`integer`、`number`、`boolean`、
`null`）、`properties`、`required`、`additionalProperties`（`false` 或一个 schema）、
`enum`、`const`、`items`、`minItems`、`maxItems`、`minimum`、`maximum`、`minLength`、
`maxLength`（按字符计）。其他关键字会被忽略——但仍会发送给提供方。
`Dskripchenko\Llm\Schema\Validator` 可以单独使用。

## 请求构建器

| 方法 | |
|---|---|
| `scenario($name)` | 日志、配额和场景路由中使用的名称（默认：提示词键或用途） |
| `about($model)`、`aboutRef($type, $id)` | 主体（多态引用） |
| `system()`、`user()`、`data()`、`history($messages)` | 手动构建的消息 |
| `schema($schema, $name)`、`tools($tools, $choice)` | |
| `options([...])`、`temperature()`、`maxTokens()` | |
| `idempotencyKey($key)` | 同一键下的成功调用会从日志中重放 |
| `quota(QuotaScope)` | 代替解析器给出的配额范围 |
| `mode(Mode)` | 为宿主携带 |
| `run()`、`stream(fn ($delta) => …)` | → `LlmResult` |
