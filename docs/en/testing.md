# Testing

## `Llm::fake()`

`Llm::fake()` replaces the HTTP transport. Everything above it runs for
real — routing and fallback, quotas, prompt rendering, schema validation
and retries, the call log — so a host's tests see the same behaviour as
production. With no route configured for a purpose, calls go to a synthetic
local provider `fake` / model `fake-model`, so tests need no provider rows.

```php
use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Testing\FakeResponse;
use Dskripchenko\Llm\Testing\RecordedCall;

// A list: answers in order, for any call. Running out throws.
Llm::fake(['first answer', ['type' => 'bug', 'priority' => 2]]);

// A map: by scenario, then prompt key, then purpose. A value is reused…
Llm::fake([
    'issue.classify' => ['type' => 'bug', 'priority' => 2],
    'summarize' => 'Short.',
    'wiki.answer' => fn (RecordedCall $call) => 'You asked: '.$call->text('user'),
    // …unless it is a sequence
    'issue.title' => Llm::sequence(['first', 'second'], whenEmpty: 'later'),
]);
// Unmatched calls get an empty text (and deterministic vectors for embed,
// the identity order for rerank).
```

| An answer can be | |
|---|---|
| a string | a text answer |
| an array | a structured answer (JSON-encoded, then validated against the schema) |
| `FakeResponse::text($s, $usage)`, `::json($a)` | with explicit usage |
| `FakeResponse::toolCall($name, $args)` | a tool call |
| `FakeResponse::embeddings($vectors)`, `::ranking($list)` | |
| `FakeResponse::unavailable(503)` | a transient failure: the next route is tried |
| `FakeResponse::rejected(400)` | a 4xx |
| a Throwable | thrown |
| a Closure | receives the `RecordedCall`, returns one of the above |

An invalid structured answer followed by a valid one exercises the real
retry. Token usage is estimated (four characters per token) unless given,
so quotas behave.

## Assertions

```php
Llm::assertSentCount(2);
Llm::assertNothingSent();
Llm::assertPurpose('classify', 1);
Llm::assertScenario('issue.classify');
Llm::assertSent(fn (RecordedCall $c) => $c->contains('It crashes', 'user') && $c->model() === 'fake-model');
Llm::assertNotSent(fn (RecordedCall $c) => $c->purpose()->value === 'embed');
Llm::recorded();   // list<RecordedCall>: type, provider, request, purpose(), scenario(), promptKey(), messages(), text($role)
```

`Llm::fake()` also returns the `FakeTransport` with the same methods.

## Testing at the HTTP level

To test the transport itself, use `Http::fake()` with OpenAI-shaped bodies; a
streamed answer is a `text/event-stream` body of `data: {...}` lines ending
with `data: [DONE]`.

## Processes

The `llm.run` effect runs on the engine's `ProcessTest` with the LLM fake
when effects are resolved from Laravel's container:

```php
$test = new ProcessTest(container: new class(app()) implements \Dskripchenko\ProcessEngine\Runtime\Container {
    public function __construct(private $app) {}
    public function get(string $class): object { return $this->app->make($class); }
});

Llm::fake(['issue.classify' => ['type' => 'bug', 'priority' => 2]]);
$test->begin(IssueTriage::class, [...])->send('issue.created', [...])->assertIn('suggested');
```

Or fake the effect away with `->fake(RunLlm::class, returns: Send::to(...))`.

## A live smoke test

The package's own suite has one opt-in test against a local Ollama. It runs
only when `LLM_OLLAMA_URL` is set (for example `http://127.0.0.1:11434`),
uses the smallest chat model already pulled, and is skipped when Ollama is
not running or has no chat model. Every other test runs without a network:
stray HTTP requests fail the suite.

```bash
LLM_OLLAMA_URL=http://127.0.0.1:11434 vendor/bin/phpunit --group live
```
