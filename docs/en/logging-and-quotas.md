# Call log and quotas

## The call log (`llm_calls`)

One row per HTTP call to a provider — a fallback or a schema retry adds rows.

| Column | |
|---|---|
| `scenario`, `purpose`, `prompt_key`, `prompt_version` | |
| `provider_id`, `provider`, `model`, `attempt` | `attempt` is the structured-output attempt |
| `prompt_tokens`, `completion_tokens`, `total_tokens`, `duration_ms` | as reported by the provider |
| `status` | `ok`, `invalid_output`, `unavailable`, `rejected` |
| `error` | |
| `subject_type`, `subject_id` | the morph reference given with `about()` (strings: integer, UUID and ULID keys fit) |
| `tenant_key`, `project_key` | the quota keys |
| `idempotency_key` | |
| `on_behalf_of` | whom the call was made for (`onBehalfOf()`) |
| `attribution` | `project`, `task`, `run` given with `attribute()` |
| `cost` | from the provider's answer (`usage.cost`), else by its `prices`; null — not known |
| `request`, `response`, `result` | bodies: the JSON sent (never the API key), the raw answer, the parsed result |
| `bodies_pruned_at` | |

`Dskripchenko\Llm\Models\Call` is an ordinary model with a `subject()`
relation — build reports on it.

### Pruning

`php artisan llm:prune [--days=N]` sets `request`, `response` and `result`
to null on rows older than `llm.log.keep_bodies_days` (30) and stamps
`bodies_pruned_at`; the metrics stay. With `llm.log.schedule_prune` (on by
default) the package schedules it daily; with a schema per tenant, turn that
off and run it per tenant from your own scheduler. `Llm::prune($days)` does
the same from code.

With `llm.log.bodies` false (`LLM_LOG_BODIES=false`) no bodies are kept
at all: a call's row holds the metrics, the subject and the status, and is
stamped `bodies_pruned_at` at once — for an application whose prompts carry
what must not be stored.

## Quotas

Daily token budgets per **tenant key** and per **project key**. The host
says which keys a call counts against:

```php
use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Quota\QuotaScope;
use Dskripchenko\Llm\Transport\CallMeta;

Llm::resolveQuotaUsing(fn (CallMeta $meta, ?string $subjectType, int|string|null $subjectId) => new QuotaScope(
    tenantKey: tenant()->key,
    projectKey: Project::keyFor($subjectType, $subjectId),   // null = not limited by project
    projectLimit: null,                                      // null = llm.quota.project_tokens_per_day
));
```

- Limits default to `llm.quota.tenant_tokens_per_day` and
  `llm.quota.project_tokens_per_day` (null = unlimited); a `QuotaScope` may
  carry its own.
- Usage is the sum of `total_tokens` in the log since the start of the day
  (application timezone) — failed and invalid attempts count too.
- The check runs **before each call** (and before each schema retry):
  `QuotaExceeded` (`scope`, `key`, `limit`, `used`) is thrown and the model
  is not called. A call that starts under the limit may end above it; the
  next one is refused.
- `->quota(new QuotaScope(...))` on a request, or `quota:` of `embed()` /
  `rerank()`, bypasses the resolver.
