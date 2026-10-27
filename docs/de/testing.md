# Testen

## `Llm::fake()`

`Llm::fake()` ersetzt den HTTP-Transport. Alles darüber läuft echt —
Routing und Ausweichen, Kontingente, Prompt-Rendering, Schema-Validierung
und Wiederholungen, das Aufrufprotokoll —, sodass die Tests eines Hosts
dasselbe Verhalten sehen wie die Produktion. Ist für einen Zweck keine Route
konfiguriert, gehen Aufrufe an einen synthetischen lokalen Anbieter `fake` /
Modell `fake-model`, sodass Tests keine Anbieter-Zeilen brauchen.

```php
use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Testing\FakeResponse;
use Dskripchenko\Llm\Testing\RecordedCall;

// Eine Liste: Antworten der Reihe nach, für jeden Aufruf. Sind sie aufgebraucht, wird geworfen.
Llm::fake(['first answer', ['type' => 'bug', 'priority' => 2]]);

// Eine Map: nach Szenario, dann Prompt-Schlüssel, dann Zweck. Ein Wert wird wiederverwendet …
Llm::fake([
    'issue.classify' => ['type' => 'bug', 'priority' => 2],
    'summarize' => 'Short.',
    'wiki.answer' => fn (RecordedCall $call) => 'You asked: '.$call->text('user'),
    // … außer es ist eine Sequenz
    'issue.title' => Llm::sequence(['first', 'second'], whenEmpty: 'later'),
]);
// Nicht zugeordnete Aufrufe erhalten einen leeren Text (und deterministische Vektoren bei embed,
// die Identitätsreihenfolge bei rerank).
```

| Eine Antwort kann sein | |
|---|---|
| ein String | eine Textantwort |
| ein Array | eine strukturierte Antwort (JSON-kodiert, dann gegen das Schema validiert) |
| `FakeResponse::text($s, $usage)`, `::json($a)` | mit expliziter Nutzung (usage) |
| `FakeResponse::toolCall($name, $args)` | ein Tool-Aufruf |
| `FakeResponse::embeddings($vectors)`, `::ranking($list)` | |
| `FakeResponse::unavailable(503)` | ein vorübergehender Fehler: Die nächste Route wird versucht |
| `FakeResponse::rejected(400)` | ein 4xx |
| ein Throwable | wird geworfen |
| eine Closure | erhält den `RecordedCall`, gibt eines der obigen zurück |

Eine ungültige strukturierte Antwort, gefolgt von einer gültigen, durchläuft
die echte Wiederholung. Der Token-Verbrauch wird geschätzt (vier Zeichen pro
Token), sofern er nicht angegeben ist, damit sich Kontingente korrekt
verhalten.

## Zusicherungen

```php
Llm::assertSentCount(2);
Llm::assertNothingSent();
Llm::assertPurpose('classify', 1);
Llm::assertScenario('issue.classify');
Llm::assertSent(fn (RecordedCall $c) => $c->contains('It crashes', 'user') && $c->model() === 'fake-model');
Llm::assertNotSent(fn (RecordedCall $c) => $c->purpose()->value === 'embed');
Llm::recorded();   // list<RecordedCall>: type, provider, request, purpose(), scenario(), promptKey(), messages(), text($role)
```

`Llm::fake()` gibt außerdem den `FakeTransport` mit denselben Methoden
zurück.

## Testen auf HTTP-Ebene

Um den Transport selbst zu testen, `Http::fake()` mit Bodies im
OpenAI-Format verwenden; eine gestreamte Antwort ist ein
`text/event-stream`-Body aus `data: {...}`-Zeilen, der mit `data: [DONE]`
endet.

## Prozesse

Der Effekt `llm.run` läuft auf dem `ProcessTest` der Engine mit dem
LLM-Fake, wenn Effekte aus Laravels Container aufgelöst werden:

```php
$test = new ProcessTest(container: new class(app()) implements \Dskripchenko\ProcessEngine\Runtime\Container {
    public function __construct(private $app) {}
    public function get(string $class): object { return $this->app->make($class); }
});

Llm::fake(['issue.classify' => ['type' => 'bug', 'priority' => 2]]);
$test->begin(IssueTriage::class, [...])->send('issue.created', [...])->assertIn('suggested');
```

Oder den Effekt ganz wegfaken mit `->fake(RunLlm::class, returns: Send::to(...))`.

## Ein Live-Smoke-Test

Die eigene Testsuite des Pakets enthält einen optionalen Test gegen ein
lokales Ollama. Er läuft nur, wenn `LLM_OLLAMA_URL` gesetzt ist (zum
Beispiel `http://127.0.0.1:11434`), verwendet das kleinste bereits gezogene
Chat-Modell und wird übersprungen, wenn Ollama nicht läuft oder kein
Chat-Modell hat. Alle anderen Tests laufen ohne Netzwerk: Verirrte
HTTP-Anfragen lassen die Suite fehlschlagen.

```bash
LLM_OLLAMA_URL=http://127.0.0.1:11434 vendor/bin/phpunit --group live
```
