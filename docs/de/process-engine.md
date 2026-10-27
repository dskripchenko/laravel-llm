# Der process-engine-Effekt `llm.run`

Mit [dskripchenko/process-engine](https://github.com/dskripchenko/process-engine)
(und seiner Laravel-Brücke `dskripchenko/laravel-process-engine`) kann ein
Prozess ein Modell als dauerhaften (durable) Effekt aufrufen. Die Integration
liegt in `Dskripchenko\Llm\ProcessEngine` und wird nur geladen, wenn sie
verwendet wird; die Engine ist eine empfohlene (suggest) Abhängigkeit.

```bash
composer require dskripchenko/laravel-process-engine
```

## Registrierung

Keine, außer der Verwendung der Klasse: Ein Effekt ist eine Klasse, die die
Engine aus dem Container auflöst. `RunLlm` (Konstanten `EFFECT = 'llm.run'`,
`DONE = 'llm.done'`, `FAILED = 'llm.failed'`) deklariert
`#[Idempotent(retry: new Retry(attempts: 3, initialInterval: '30 seconds', backoff: 4.0, maxInterval: '15 minutes', attemptTimeout: '10 minutes'))]`.

## Verwendung als Aktion

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
            ->entry(RunLlm::class)                                   // liest $context['llm']
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
        $context['suggestion'] = $event['data'];   // die validierte strukturierte Antwort
    }
}
```

Die Anfrage stammt aus der Nutzlast des auslösenden Ereignisses, wenn diese
`prompt` oder `user` enthält — nur das `run:` eines Übergangs sieht das
Ereignis:

```php
->on('summarize.requested', to: 'summarizing', run: RunLlm::class)
// payload: LlmRun::ask(Purpose::Summarize, 'Summarize the discussion.', $text)->toArray()
```

andernfalls aus `$context['llm']` (eine `entry()`-Aktion hat kein Ereignis).

Als Aktion verwenden (`run:`, `entry()`), nicht mit `invoke()`: Das Ergebnis
kommt als Ereignis `llm.done` / `llm.failed` an. (Mit `invoke()` werden die
Ereignisse ebenfalls gesendet, zusätzlich feuert aber auch `done.invoke.*`.)

## `LlmRun` — die Anfrage als Daten

| | |
|---|---|
| `LlmRun::prompt($key, $variables)` | ein Preset |
| `LlmRun::ask(Purpose, $user, $data = null, $system = null)` | von Hand; `$data` wird zu einem Datenblock |
| `->scenario()`, `->about($type, $id)`, `->mode(Mode)`, `->tag()`, `->options([...])` | |
| `->toArray()` / `LlmRun::fromArray()` | |

## Die Ereignisse

`llm.done`: `text`, `data`, `tool_calls`, `usage`, `provider`, `model`,
`call_id`, `attempts`, `finish_reason`, `replayed`, `mode`, `tag`, `key`.

`llm.failed`: `code`, `message`, `details`, `mode`, `tag`, `key`. Codes:
`unavailable`, `rejected`, `invalid_output`, `quota_exceeded`,
`cloud_not_enabled`, `no_route`, `invalid_prompt`, `invalid_request`.

## Wiederholungen und Idempotenz

- Der Idempotenzschlüssel ist der Effektschlüssel der Engine — Instanz +
  Schritt + Aktion (`{instance}/step{n}/{action}#{i}`). Der Handler übergibt
  ihn an den Aufruf; ein erfolgreicher, unter demselben Schlüssel
  protokollierter Aufruf wird aus dem Protokoll **wiedergegeben**
  (`replayed: true`), statt das Modell erneut aufzurufen — etwa wenn ein
  Worker abgestürzt ist, nachdem das Modell geantwortet hat, aber bevor das
  Ereignis gespeichert wurde.
- Vorübergehende Fehler (alle Anbieter nicht erreichbar) werden geworfen,
  und die Engine wiederholt gemäß der Richtlinie. Beim letzten Versuch
  (`llm.effect.attempts`, gleich der Versuchszahl der Richtlinie halten) wird
  stattdessen `llm.failed` mit dem Code `unavailable` gesendet.
- Jeder andere Fehler wird sofort als `llm.failed` gesendet — er würde auch
  bei einer Wiederholung nicht durchgehen.
- Andere Ausnahmen (ein Datenbankausfall) gelangen als mehrdeutige Fehler zur
  Engine und werden wiederholt.

## Mensch in der Schleife: `Mode`

`Dskripchenko\Llm\Mode` sind Daten, die der Prozess mit der Anfrage mitführt
und in `llm.done.mode` zurückerhält. Darüber hinaus macht das Paket nichts
damit; Host-Prozess und Oberfläche setzen den Ablauf um.

| Modus | Vorgesehener Ablauf |
|---|---|
| `apply` | Der Prozess übernimmt die Antwort sofort (`on(llm.done)` schreibt sie). Für risikoarme, umkehrbare, sichtbare Änderungen; diese im Verlauf festhalten, damit ein Mensch sie rückgängig machen kann. |
| `suggest` (Standard) | Der Prozess speichert die Antwort als Vorschlag neben dem Gegenstand und macht weiter; ein Mensch übernimmt, bearbeitet oder verwirft ihn, wann immer er möchte. Ohne ihn ändert sich nichts. |
| `approve` | Der Prozess wartet in einem Zustand (`on('suggestion.approved')`, `on('suggestion.rejected')`, ggf. `after('3 days')`), bis ein Mensch entscheidet; nur eine Freigabe übernimmt die Antwort. |

`Mode::appliesAutomatically()` und `Mode::waitsForHuman()` helfen beim
Verzweigen. Die Antwort eines Modells ist in jedem Modus nicht
vertrauenswürdige Eingabe — sie ist so zu validieren wie die eines Menschen.
