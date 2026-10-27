# dskripchenko/laravel-llm

> 🌐 [English](../../README.md) · **Русский** · [Deutsch](../de/README.md) · [中文](../zh/README.md)

Локальные LLM для Laravel через OpenAI-совместимый API, который понимают
Ollama, vLLM, LM Studio и llama.cpp server, — без SDK, только HTTP-клиент
Laravel. Вызывающий код запрашивает **назначение** (`summarize`,
`classify`…), а не модель: провайдеры и маршруты хранятся в базе данных,
с резервным маршрутом на случай, если провайдер недоступен. Промпты — это
пресеты в коде, которые арендаторы могут переопределять с версиями;
структурированные ответы проверяются по JSON-схеме и запрашиваются повторно
с переданными модели ошибками; текст пользователя всегда помечается как
данные. Каждый вызов журналируется с токенами и длительностью, тела запросов
очищаются через 30 дней, а суточные квоты токенов проверяются до обращения
к модели. Эффект для process-engine выполняет вызовы внутри долгоживущих
процессов, а `Llm::fake()` делает всё это тестируемым.

## Установка

```bash
composer require dskripchenko/laravel-llm
php artisan migrate
```

PHP 8.2–8.5, Laravel 11, 12 или 13. Таблицы (`llm_providers`, `llm_routes`,
`llm_prompt_templates`, `llm_calls`) живут в подключении по умолчанию — при
схеме на каждого арендатора задайте `LLM_RUN_MIGRATIONS=false`, опубликуйте
миграцию (`php artisan vendor:publish --tag=llm-migrations`) в путь миграций
арендаторов и выполняйте её для каждого арендатора.

## Настройка провайдера и маршрутов

```php
use Dskripchenko\Llm\Models\Provider;
use Dskripchenko\Llm\Models\Route;

$gpu = Provider::create(['name' => 'gpu-box', 'base_url' => 'http://10.0.0.5:8000/v1', 'limits' => ['timeout' => 180]]);
$cpu = Provider::create(['name' => 'ollama',  'base_url' => 'http://127.0.0.1:11434/v1']);

$fallback = Route::create(['purpose' => 'summarize', 'provider_id' => $cpu->id, 'model' => 'llama3.2:3b']);
Route::create(['purpose' => 'summarize', 'provider_id' => $gpu->id, 'model' => 'qwen2.5:32b',
               'fallback_route_id' => $fallback->id, 'options' => ['temperature' => 0.2]]);
```

Тайм-аут, ошибка соединения, 5xx или 429 переводят вызов на резервный
маршрут; прочие 4xx — нет. **Облачный провайдер (`is_cloud`) не используется,
пока администратор не установит `cloud_enabled`** — промпты содержат данные
арендатора.

## Вызов модели

```php
use Dskripchenko\Llm\Facades\Llm;

// Из пресета (см. prompts-and-schemas.md)
$result = Llm::prompt(ClassifyIssue::class, ['body' => $issue->description])
    ->about($issue)              // журналируется как предмет вызова
    ->run();
$result->data;                   // ['type' => 'bug', 'priority' => 2], проверено по схеме

// Вручную
$result = Llm::request('summarize')
    ->system('Summarize the issue in two sentences.')
    ->user('Issue:')
    ->data('issue', $issue->description)     // недоверенный текст → блок данных
    ->stream(function (string $delta): void { echo $delta; });

Llm::embed(['first text', 'second text'])->vectors;
Llm::rerank('query', $documents)->order();
```

## Тестирование кода

```php
Llm::fake(['issue.classify' => ['type' => 'bug', 'priority' => 2], 'summarize' => 'Short.']);

// … прогоните свой код …

Llm::assertPurpose('classify', 1);
Llm::assertSent(fn ($call) => $call->contains('It crashes', 'user'));
```

## Документация

- [Провайдеры и маршруты](providers-and-routes.md)
- [Промпты, схемы и пользовательские данные](prompts-and-schemas.md)
- [Журнал вызовов и квоты](logging-and-quotas.md)
- [Эффект `llm.run` для process-engine](process-engine.md)
- [Тестирование](testing.md)

## Тесты пакета

```bash
composer test      # PHPUnit + Testbench, SQLite в памяти, без сети
composer analyse   # PHPStan, уровень max
composer format    # Pint
```

## Лицензия

MIT. См. [LICENSE](../../LICENSE).
