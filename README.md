# dskripchenko/laravel-llm

> 🌐 **English** · [Русский](docs/ru/README.md) · [Deutsch](docs/de/README.md) · [中文](docs/zh/README.md)

Local LLMs for Laravel through the OpenAI-compatible API that Ollama, vLLM,
LM Studio and llama.cpp server all speak — no SDK, only Laravel's Http
client. Callers ask for a **purpose** (`summarize`, `classify`…), never a
model: providers and routes live in the database, with a fallback route when
a provider is down. Prompts are presets in code that tenants may override
with versions; structured answers are validated against a JSON schema and
retried with the errors fed back; user text is always marked as data. Every
call is logged with tokens and duration, bodies are pruned after 30 days,
and daily token quotas are checked before a model is called. A
process-engine effect runs calls inside long-running processes, and
`Llm::fake()` makes all of it testable.

## Install

```bash
composer require dskripchenko/laravel-llm
php artisan migrate
```

PHP 8.2–8.5, Laravel 11, 12 or 13. The tables (`llm_providers`, `llm_routes`,
`llm_prompt_templates`, `llm_calls`) live on the default connection — with a
schema per tenant, set `LLM_RUN_MIGRATIONS=false`, publish the migration
(`php artisan vendor:publish --tag=llm-migrations`) into the tenant path and
run it per tenant.

## Configure a provider and routes

```php
use Dskripchenko\Llm\Models\Provider;
use Dskripchenko\Llm\Models\Route;

$gpu = Provider::create(['name' => 'gpu-box', 'base_url' => 'http://10.0.0.5:8000/v1', 'limits' => ['timeout' => 180]]);
$cpu = Provider::create(['name' => 'ollama',  'base_url' => 'http://127.0.0.1:11434/v1']);

$fallback = Route::create(['purpose' => 'summarize', 'provider_id' => $cpu->id, 'model' => 'llama3.2:3b']);
Route::create(['purpose' => 'summarize', 'provider_id' => $gpu->id, 'model' => 'qwen2.5:32b',
               'fallback_route_id' => $fallback->id, 'options' => ['temperature' => 0.2]]);
```

A timeout, a connection error, a 5xx or a 429 moves on to the fallback; a
4xx does not. **A cloud provider (`is_cloud`) is never used until an
administrator sets `cloud_enabled`** — prompts carry tenant data.

## Call a model

```php
use Dskripchenko\Llm\Facades\Llm;

// From a preset (see docs/en/prompts-and-schemas.md)
$result = Llm::prompt(ClassifyIssue::class, ['body' => $issue->description])
    ->about($issue)              // logged as the call's subject
    ->run();
$result->data;                   // ['type' => 'bug', 'priority' => 2], validated

// By hand
$result = Llm::request('summarize')
    ->system('Summarize the issue in two sentences.')
    ->user('Issue:')
    ->data('issue', $issue->description)     // untrusted text → a data block
    ->stream(function (string $delta): void { echo $delta; });

Llm::embed(['first text', 'second text'])->vectors;
Llm::rerank('query', $documents)->order();
```

## Test it

```php
Llm::fake(['issue.classify' => ['type' => 'bug', 'priority' => 2], 'summarize' => 'Short.']);

// … exercise your code …

Llm::assertPurpose('classify', 1);
Llm::assertSent(fn ($call) => $call->contains('It crashes', 'user'));
```

## Documentation

- [Providers and routes](docs/en/providers-and-routes.md)
- [Prompts, schemas and user data](docs/en/prompts-and-schemas.md)
- [Call log and quotas](docs/en/logging-and-quotas.md)
- [The `llm.run` process-engine effect](docs/en/process-engine.md)
- [Testing](docs/en/testing.md)

## Testing

```bash
composer test      # PHPUnit + Testbench, SQLite in memory, no network
composer analyse   # PHPStan, level max
composer format    # Pint
```

## License

MIT. See [LICENSE](LICENSE).
