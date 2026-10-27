# Журнал вызовов и квоты

## Журнал вызовов (`llm_calls`)

Одна строка на каждый HTTP-вызов провайдера — переход на резервный маршрут
или повтор по схеме добавляют строки.

| Колонка | |
|---|---|
| `scenario`, `purpose`, `prompt_key`, `prompt_version` | |
| `provider_id`, `provider`, `model`, `attempt` | `attempt` — номер попытки получить структурированный ответ |
| `prompt_tokens`, `completion_tokens`, `total_tokens`, `duration_ms` | как сообщил провайдер |
| `status` | `ok`, `invalid_output`, `unavailable`, `rejected` |
| `error` | |
| `subject_type`, `subject_id` | morph-ссылка, переданная через `about()` (строки: подходят целочисленные, UUID- и ULID-ключи) |
| `tenant_key`, `project_key` | ключи квот |
| `idempotency_key` | |
| `request`, `response`, `result` | тела: отправленный JSON (API-ключ туда никогда не попадает), сырой ответ, разобранный результат |
| `bodies_pruned_at` | |

`Dskripchenko\Llm\Models\Call` — обычная модель со связью `subject()`;
стройте на ней отчёты.

### Очистка

`php artisan llm:prune [--days=N]` обнуляет `request`, `response` и `result`
у строк старше `llm.log.keep_bodies_days` (30) и проставляет
`bodies_pruned_at`; метрики остаются. При `llm.log.schedule_prune`
(включено по умолчанию) пакет планирует её ежедневно; при схеме на каждого
арендатора отключите это и запускайте очистку для каждого арендатора из
своего планировщика. `Llm::prune($days)` делает то же из кода.

## Квоты

Суточные бюджеты токенов на **ключ арендатора** и на **ключ проекта**. Хост
указывает, в счёт каких ключей идёт вызов:

```php
use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Quota\QuotaScope;
use Dskripchenko\Llm\Transport\CallMeta;

Llm::resolveQuotaUsing(fn (CallMeta $meta, ?string $subjectType, int|string|null $subjectId) => new QuotaScope(
    tenantKey: tenant()->key,
    projectKey: Project::keyFor($subjectType, $subjectId),   // null = без лимита по проекту
    projectLimit: null,                                      // null = llm.quota.project_tokens_per_day
));
```

- Лимиты по умолчанию берутся из `llm.quota.tenant_tokens_per_day` и
  `llm.quota.project_tokens_per_day` (null = без ограничений); `QuotaScope`
  может нести собственные.
- Расход — это сумма `total_tokens` в журнале с начала суток (в часовом
  поясе приложения); неудачные и невалидные попытки тоже учитываются.
- Проверка выполняется **перед каждым вызовом** (и перед каждым повтором
  по схеме): выбрасывается `QuotaExceeded` (`scope`, `key`, `limit`, `used`),
  и модель не вызывается. Вызов, начатый в пределах лимита, может закончиться
  сверх него; следующий будет отклонён.
- `->quota(new QuotaScope(...))` у запроса или `quota:` у `embed()` /
  `rerank()` обходят резолвер.
