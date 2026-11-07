# Тестирование

## `Llm::fake()`

`Llm::fake()` подменяет HTTP-транспорт. Всё, что выше него, работает
по-настоящему — маршрутизация и переход на резервный маршрут, квоты,
рендеринг промптов, проверка по схеме и повторы, журнал вызовов, — поэтому
тесты хоста видят то же поведение, что и в продакшене. Если для назначения
не настроен маршрут, вызовы уходят синтетическому локальному провайдеру
`fake` / модели `fake-model`, так что тестам не нужны строки провайдеров.

```php
use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Testing\FakeResponse;
use Dskripchenko\Llm\Testing\RecordedCall;

// Список: ответы по порядку, для любого вызова. Когда они кончаются — исключение.
Llm::fake(['first answer', ['type' => 'guide', 'level' => 2]]);

// Карта: по сценарию, затем по ключу промпта, затем по назначению. Значение используется повторно…
Llm::fake([
    'article.classify' => ['type' => 'guide', 'level' => 2],
    'summarize' => 'Short.',
    'wiki.answer' => fn (RecordedCall $call) => 'You asked: '.$call->text('user'),
    // …если это не последовательность
    'article.title' => Llm::sequence(['first', 'second'], whenEmpty: 'later'),
]);
// Вызовы без совпадения получают пустой текст (а также детерминированные векторы
// для embed и тождественный порядок для rerank).
```

| Ответом может быть | |
|---|---|
| строка | текстовый ответ |
| массив | структурированный ответ (кодируется в JSON, затем проверяется по схеме) |
| `FakeResponse::text($s, $usage)`, `::json($a)` | с явно заданным расходом токенов |
| `FakeResponse::toolCall($name, $args)` | вызов инструмента |
| `FakeResponse::embeddings($vectors)`, `::ranking($list)` | |
| `FakeResponse::unavailable(503)` | временный сбой: пробуется следующий маршрут |
| `FakeResponse::rejected(400)` | 4xx |
| Throwable | выбрасывается |
| Closure | получает `RecordedCall`, возвращает одно из перечисленного |

Невалидный структурированный ответ, за которым следует валидный,
прогоняет настоящий повтор. Расход токенов оценивается (четыре символа на
токен), если не задан явно, так что квоты работают как обычно.

## Проверки

```php
Llm::assertSentCount(2);
Llm::assertNothingSent();
Llm::assertPurpose('classify', 1);
Llm::assertScenario('article.classify');
Llm::assertSent(fn (RecordedCall $c) => $c->contains('To reset a password, open Settings', 'user') && $c->model() === 'fake-model');
Llm::assertNotSent(fn (RecordedCall $c) => $c->purpose()->value === 'embed');
Llm::recorded();   // list<RecordedCall>: type, provider, request, purpose(), scenario(), promptKey(), messages(), text($role)
```

`Llm::fake()` также возвращает `FakeTransport` с теми же методами.

## Тестирование на уровне HTTP

Чтобы протестировать сам транспорт, используйте `Http::fake()` с телами
в формате OpenAI; потоковый ответ — это тело `text/event-stream` из строк
`data: {...}`, завершающееся `data: [DONE]`.

## Процессы

Эффект `llm.run` работает в `ProcessTest` движка вместе с фейком LLM, если
эффекты получаются из контейнера Laravel:

```php
$test = new ProcessTest(container: new class(app()) implements \Dskripchenko\ProcessEngine\Runtime\Container {
    public function __construct(private $app) {}
    public function get(string $class): object { return $this->app->make($class); }
});

Llm::fake(['article.classify' => ['type' => 'guide', 'level' => 2]]);
$test->begin(ArticleReview::class, [...])->send('article.published', [...])->assertIn('suggested');
```

Или подмените эффект целиком через `->fake(RunLlm::class, returns: Send::to(...))`.

## Живой смоук-тест

В собственном наборе тестов пакета есть один включаемый по желанию тест
против локальной Ollama. Он запускается, только если задана `LLM_OLLAMA_URL`
(например, `http://127.0.0.1:11434`), использует самую маленькую из уже
скачанных чат-моделей и пропускается, если Ollama не запущена или в ней нет
чат-модели. Все остальные тесты работают без сети: случайные HTTP-запросы
роняют набор.

```bash
LLM_OLLAMA_URL=http://127.0.0.1:11434 vendor/bin/phpunit --group live
```
