# 调用日志与配额

## 调用日志（`llm_calls`）

每次对提供方的 HTTP 调用对应一行——备用路由或 schema 重试会增加新的行。

| 列 | |
|---|---|
| `scenario`、`purpose`、`prompt_key`、`prompt_version` | |
| `provider_id`、`provider`、`model`、`attempt` | `attempt` 是结构化输出的尝试序号 |
| `prompt_tokens`、`completion_tokens`、`total_tokens`、`duration_ms` | 按提供方报告的数值 |
| `status` | `ok`、`invalid_output`、`unavailable`、`rejected` |
| `error` | |
| `subject_type`、`subject_id` | 通过 `about()` 给出的多态引用（字符串：整数、UUID 和 ULID 键都可容纳） |
| `tenant_key`、`project_key` | 配额键 |
| `idempotency_key` | |
| `request`、`response`、`result` | 请求/响应体：发送的 JSON（绝不包含 API 密钥）、原始回答、解析后的结果 |
| `bodies_pruned_at` | |

`Dskripchenko\Llm\Models\Call` 是带有 `subject()` 关联的普通模型——可以在其上构建报表。

### 清理

`php artisan llm:prune [--days=N]` 会把早于 `llm.log.keep_bodies_days`（30）的行中的
`request`、`response` 和 `result` 置为 null，并写入 `bodies_pruned_at`；指标保留。开启
`llm.log.schedule_prune`（默认开启）时，本包会将其安排为每日任务；如果每个租户一个 schema，
请关闭此项，并在你自己的调度器中按租户执行。`Llm::prune($days)` 在代码中完成同样的事。

## 配额

按**租户键**和**项目键**设置的每日令牌预算。由宿主应用指定某次调用计入哪些键：

```php
use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Quota\QuotaScope;
use Dskripchenko\Llm\Transport\CallMeta;

Llm::resolveQuotaUsing(fn (CallMeta $meta, ?string $subjectType, int|string|null $subjectId) => new QuotaScope(
    tenantKey: tenant()->key,
    projectKey: Project::keyFor($subjectType, $subjectId),   // null = 不按项目限制
    projectLimit: null,                                      // null = llm.quota.project_tokens_per_day
));
```

- 限额默认取 `llm.quota.tenant_tokens_per_day` 和 `llm.quota.project_tokens_per_day`
  （null = 不限）；`QuotaScope` 也可以携带自己的限额。
- 用量是日志中自当天开始（应用时区）以来 `total_tokens` 的总和——失败和无效的尝试同样计入。
- 检查在**每次调用之前**（以及每次 schema 重试之前）进行：抛出 `QuotaExceeded`
  （`scope`、`key`、`limit`、`used`），且不会调用模型。一次在限额内开始的调用结束时可能超出
  限额；下一次调用将被拒绝。
- 在请求上使用 `->quota(new QuotaScope(...))`，或在 `embed()` / `rerank()` 中使用
  `quota:` 参数，可以绕过解析器。
