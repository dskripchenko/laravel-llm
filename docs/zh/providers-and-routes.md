# 提供方与路由

## 提供方（`llm_providers`）

| 列 | |
|---|---|
| `name` | 唯一，显示在日志中 |
| `base_url` | OpenAI 兼容的根地址，**包含 `/v1`**：`http://127.0.0.1:11434/v1`（Ollama）、`http://host:8000/v1`（vLLM）、`http://127.0.0.1:1234/v1`（LM Studio）、`http://127.0.0.1:8080/v1`（llama.cpp server） |
| `api_key` | 可选；使用应用密钥加密；以 `Authorization: Bearer` 发送 |
| `models` | 提供方所服务模型的说明性列表（供管理界面使用） |
| `limits` | `timeout`（秒，覆盖 `llm.http.timeout`）、`max_output_tokens`（限制每个请求的 `max_tokens` 上限） |
| `is_cloud` | 提供方位于租户基础设施之外 |
| `cloud_enabled` | 管理员已允许使用该云端提供方 |
| `is_enabled` | 已禁用的提供方会被跳过 |
| `attribution` | 以请求头接收调用的归属（按此自行记账的网关）；默认为 false |
| `prices` | 按模型，或用 `*` 表示任意模型：每百万 token `{"input": 3, "output": 15}`；调用日志的 `cost` 按此计算 |

### 云端提供方

> **警告。** 提示词包含租户的数据——文章、评论、消息。把它发送到云端 API 会使这些数据
> 离开租户的基础设施。因此，除非同时设置了 `cloud_enabled = true`，本包永远不会路由到
> `is_cloud = true` 的提供方。请只向有权做出此决定的管理员开放 `cloud_enabled`，并把每个
> 非本地端点都标记为 `is_cloud`：本包无法判断一个 URL 指向哪里。

被拒绝的云端路由会被跳过（并在应用日志中写入警告），转而尝试其备用路由；当没有剩余路由时，
抛出 `CloudProviderNotEnabled`，且不会发送任何内容。

### 调用归属与费用

调用可以说明代表谁发起、属于哪项工作：

```php
Llm::prompt(SummarizeArticle::class, ['article' => $text])
    ->onBehalfOf($user->external_id)           // 人员或智能体在登录提供方处的标识
    ->attribute(project: 'KB', task: 'KB-12', run: 'run-7')
    ->run();

Llm::embed($texts, attribution: new Attribution(onBehalfOf: 'agent-7'));
```

调用日志始终保存这些信息（`on_behalf_of`、`attribution`）。只有 `attribution = true` 的提供方才会收到它们，以请求头 `X-On-Behalf-Of`、`X-Project`、`X-Task` 和 `X-Run` 发送（`llm.attribution.headers`）：模型前面的网关据此自行记账，不会继续转发。其他任何提供方——尤其是云端厂商——都不会收到。

日志中的 `cost` 取提供方应答中给出的值（`usage.cost`），否则按提供方的 `prices` 由 token 数计算；两者都未知时为 null。

## 路由（`llm_routes`）

路由把一个**用途**——`chat`、`summarize`、`classify`、`extract`、`embed`、`rerank`
（`Dskripchenko\Llm\Purpose`）——映射到一个提供方和一个模型。

| 列 | |
|---|---|
| `purpose` | |
| `scenario` | 可选：仅用于某一场景的路由（例如 `wiki.answer`）；没有场景路由时使用用途路由 |
| `provider_id`、`model` | |
| `fallback_route_id` | 本路由不可用时尝试的路由 |
| `options` | 该模型的默认请求选项（`temperature`、`num_ctx`……）；提示词或调用方的选项会覆盖它们 |
| `is_active` | 未激活的路由会被跳过（但仍会沿其备用路由继续） |

某个用途的**入口路由**是同一用途中没有其他路由以它为备用的那条激活路由（若有多条，取最早的）。
从这里开始，链路沿 `fallback_route_id` 前进（环会被切断，最多 16 步）。

### 哪些情况会转到备用路由

| 失败 | 结果 |
|---|---|
| 连接被拒绝、DNS、超时 | 下一条路由 |
| HTTP 5xx、429 | 下一条路由 |
| 响应体不是 JSON、没有 choices | 下一条路由 |
| HTTP 4xx（其他） | `ProviderRejected`，不转备用——请求本身有误 |
| 在第一个增量之后中断的流 | `ProviderUnavailable`，不转备用——调用方已经拿到部分回答 |
| 所有路由都失败 | `ProviderUnavailable`（`isTransient() === true`） |

每次 HTTP 尝试都是调用日志中的一行，失败的也包括在内。

## 协议

- `POST {base}/chat/completions`，携带 `model`、`messages`、选项，提供时还有
  `tools` / `tool_choice`，结构化回答时携带
  `response_format: {type: json_schema, json_schema: {name, schema, strict: true}}`。
  流式模式额外添加 `stream: true` 和 `stream_options: {include_usage: true}`，并读取
  server-sent events。
- `POST {base}/embeddings`，携带 `model` 和 `input`（列表）。
- `POST {base}/rerank`，携带 `model`、`query`、`documents`、`top_n`——即 vLLM 和
  llama.cpp server 提供的 Jina/Cohere 格式。Ollama 和 LM Studio 没有 rerank 端点；请把
  `rerank` 路由到支持它的服务器。

工具调用通过 `LlmResult::$toolCalls` 返回（名称、已解码的参数）；本包不会执行工具，也不会
循环——请通过 `history()` 把结果传回并再次调用。

## 配置（`config/llm.php`）

| 键 | 默认值 | |
|---|---|---|
| `http.timeout`、`http.connect_timeout` | 120, 5 | 秒 |
| `structured.max_attempts` | 3 | 结构化回答的总尝试次数 |
| `prompts.presets` | `[]` | 预设类 |
| `log.enabled` | true | |
| `log.keep_bodies_days` | 30 | |
| `log.schedule_prune` | true | 每日调度 `llm:prune` |
| `quota.tenant_tokens_per_day`、`quota.project_tokens_per_day` | null | 默认限额 |
| `attribution.headers` | `X-On-Behalf-Of`、`X-Project`、`X-Task`、`X-Run` | 发往启用 `attribution` 的提供方时使用的请求头名；名称为空则不发送 |
| `effect.attempts` | 3 | 必须与 `RunLlm` 的重试策略一致 |
