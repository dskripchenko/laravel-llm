# dskripchenko/laravel-llm

> 🌐 [English](../../README.md) · [Русский](../ru/README.md) · **Deutsch** · [中文](../zh/README.md)

Lokale LLMs für Laravel über die OpenAI-kompatible API, die Ollama, vLLM,
LM Studio und der llama.cpp-Server gleichermaßen sprechen — ohne SDK, nur mit
Laravels Http-Client. Aufrufer fragen nach einem **Zweck** (`summarize`,
`classify` …), nie nach einem Modell: Anbieter und Routen liegen in der
Datenbank, mit einer Ausweichroute, wenn ein Anbieter ausfällt. Prompts sind
Presets im Code, die Mandanten mit Versionen überschreiben können;
strukturierte Antworten werden gegen ein JSON-Schema validiert und mit den
zurückgemeldeten Fehlern erneut angefordert; Benutzertext wird immer als Daten
markiert. Jeder Aufruf wird mit Tokens und Dauer protokolliert, die Inhalte
werden nach 30 Tagen bereinigt, und tägliche Token-Kontingente werden geprüft,
bevor ein Modell aufgerufen wird. Ein process-engine-Effekt führt Aufrufe
innerhalb langlaufender Prozesse aus, und `Llm::fake()` macht all das
testbar.

## Installation

```bash
composer require dskripchenko/laravel-llm
php artisan migrate
```

PHP 8.2–8.5, Laravel 11, 12 oder 13. Die Tabellen (`llm_providers`,
`llm_routes`, `llm_prompt_templates`, `llm_calls`) liegen auf der
Standardverbindung — bei einem Schema pro Mandant `LLM_RUN_MIGRATIONS=false`
setzen, die Migration veröffentlichen
(`php artisan vendor:publish --tag=llm-migrations`), in den Mandantenpfad
legen und je Mandant ausführen.

## Anbieter und Routen konfigurieren

```php
use Dskripchenko\Llm\Models\Provider;
use Dskripchenko\Llm\Models\Route;

$gpu = Provider::create(['name' => 'gpu-box', 'base_url' => 'http://10.0.0.5:8000/v1', 'limits' => ['timeout' => 180]]);
$cpu = Provider::create(['name' => 'ollama',  'base_url' => 'http://127.0.0.1:11434/v1']);

$fallback = Route::create(['purpose' => 'summarize', 'provider_id' => $cpu->id, 'model' => 'llama3.2:3b']);
Route::create(['purpose' => 'summarize', 'provider_id' => $gpu->id, 'model' => 'qwen2.5:32b',
               'fallback_route_id' => $fallback->id, 'options' => ['temperature' => 0.2]]);
```

Ein Timeout, ein Verbindungsfehler, ein 5xx oder ein 429 führt zur
Ausweichroute, ein 4xx nicht. **Ein Cloud-Anbieter (`is_cloud`) wird nie
verwendet, bevor ein Administrator `cloud_enabled` setzt** — Prompts
enthalten Mandantendaten.

## Ein Modell aufrufen

```php
use Dskripchenko\Llm\Facades\Llm;

// Aus einem Preset (siehe docs/de/prompts-and-schemas.md)
$result = Llm::prompt(ClassifyIssue::class, ['body' => $issue->description])
    ->about($issue)              // als Gegenstand des Aufrufs protokolliert
    ->run();
$result->data;                   // ['type' => 'bug', 'priority' => 2], validiert

// Von Hand
$result = Llm::request('summarize')
    ->system('Summarize the issue in two sentences.')
    ->user('Issue:')
    ->data('issue', $issue->description)     // nicht vertrauenswürdiger Text → ein Datenblock
    ->stream(function (string $delta): void { echo $delta; });

Llm::embed(['first text', 'second text'])->vectors;
Llm::rerank('query', $documents)->order();
```

## Testen

```php
Llm::fake(['issue.classify' => ['type' => 'bug', 'priority' => 2], 'summarize' => 'Short.']);

// … den eigenen Code ausführen …

Llm::assertPurpose('classify', 1);
Llm::assertSent(fn ($call) => $call->contains('It crashes', 'user'));
```

## Dokumentation

- [Anbieter und Routen](providers-and-routes.md)
- [Prompts, Schemas und Benutzerdaten](prompts-and-schemas.md)
- [Aufrufprotokoll und Kontingente](logging-and-quotas.md)
- [Der process-engine-Effekt `llm.run`](process-engine.md)
- [Testen](testing.md)

## Tests

```bash
composer test      # PHPUnit + Testbench, SQLite im Speicher, kein Netzwerk
composer analyse   # PHPStan, Level max
composer format    # Pint
```

## Lizenz

MIT. Siehe [LICENSE](../../LICENSE).
