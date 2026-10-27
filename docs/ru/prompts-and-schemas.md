# Промпты, схемы и пользовательские данные

## Пресеты в коде

```php
use Dskripchenko\Llm\Prompts\Prompt;
use Dskripchenko\Llm\Purpose;

final class ClassifyIssue extends Prompt
{
    public function key(): string { return 'issue.classify'; }

    public function purpose(): Purpose { return Purpose::Classify; }

    public function system(): string
    {
        return 'You triage issues of project {{ project }}.';
    }

    public function user(): string
    {
        return "Labels in use:\n{{ labels }}\n\nThe issue:\n{{ body }}";
    }

    public function variables(): array
    {
        return ['project' => 'string', 'labels' => '?list', 'body' => 'data'];
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['bug', 'feature', 'question']],
                'priority' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
            ],
            'required' => ['type', 'priority'],
            'additionalProperties' => false,
        ];
    }

    public function options(): array { return ['temperature' => 0]; }
}
```

Регистрируйте пресеты в `llm.prompts.presets` (или через
`Llm::prompts()->register()`; класс пресета, переданный в `Llm::prompt()`,
регистрируется сам). Шаблоны проверяются при регистрации пресета: каждый
`{{ placeholder }}` должен быть объявленной переменной.

### Переменные — типизированный контекст

| Тип | Принимает | Выводится как |
|---|---|---|
| `string` | string, Stringable | как есть — **доверенный текст, написанный хостом** |
| `int`, `float`, `bool` | | их строковое представление |
| `list` | список | по строке `- item` на элемент |
| `json` | что угодно | JSON |
| `data` | string, Stringable | блок данных (см. ниже) |

`?type` делает переменную необязательной. Отсутствующая переменная,
переменная неверного типа или переменная, которую пресет не объявляет,
приводят к `InvalidPrompt` (со всеми проблемами сразу) до какой-либо
отправки.

## Текст пользователя — это данные, а не инструкции

Всё, что написал пользователь, — описание задачи, комментарий, страница —
должно быть переменной типа `data` или проходить через `->data($name, $text)`
в запросе, собранном вручную. Такой текст оборачивается как

```
<<<DATA body 3f9a0c2d7e11>>>
…the text…
<<<END DATA 3f9a0c2d7e11>>>
```

с границей, генерируемой случайно для каждого запроса (текст не может закрыть
свой блок, а любое вхождение границы внутри него удаляется), а системное
сообщение получает инструкцию: блоки данных — это недоверенный материал для
работы, а не инструкции, и они никогда не меняют задачу или формат ответа.
Это затрудняет prompt injection, но не делает её невозможной. Считайте ответ
модели недоверенными входными данными и используйте режимы `suggest` /
`approve` для всего, что меняет данные (см. [process-engine](process-engine.md)).

## Переопределения арендатора с версиями

```php
$prompts = Llm::prompts();

$prompts->override('issue.classify', user: "{{ body }}", system: 'Be strict.', note: 'stricter');  // v1, активна
$prompts->override('issue.classify', user: "…", activate: false);   // v2, черновик
$prompts->activate('issue.classify', 2);                            // v2 в работе
$prompts->revert('issue.classify');                                  // назад к пресету в коде
$prompts->versions('issue.classify');                                // история
```

Переопределение заменяет `system`, `user` и, если передана, `schema`;
переменные, назначение и опции остаются от пресета. Переопределение,
использующее необъявленный плейсхолдер, отклоняется. Версии только растут;
активная версия журналируется с каждым вызовом (`prompt_version`, null для
пресета).

## Структурированные ответы

`schema()` пресета или `->schema($schema, $name)` запрашивают JSON:

1. схема уходит провайдеру как `response_format` (`json_schema`, `strict`)
   и, для моделей, которые его игнорируют, — в системное сообщение;
2. ответ разбирается (допускаются Markdown-ограждение, начальный блок
   `<think>` или текст вокруг одного объекта) и проверяется;
3. невалидный ответ журналируется как `invalid_output`, и модель
   запрашивается снова с приложенными её ответом и ошибками — всего до
   `llm.structured.max_attempts` (3) попыток;
4. затем — `InvalidStructuredOutput` (`errors`, `lastOutput`, `attempts`).

`LlmResult::$data` содержит проверенное значение в виде массивов; `$attempts`
и `$usage` учитывают все попытки.

### Поддерживаемое подмножество схемы

`type` (один или список: `object`, `array`, `string`, `integer`, `number`,
`boolean`, `null`), `properties`, `required`, `additionalProperties`
(`false` или схема), `enum`, `const`, `items`, `minItems`, `maxItems`,
`minimum`, `maximum`, `minLength`, `maxLength` (в символах). Прочие ключевые
слова игнорируются — но всё равно отправляются провайдеру.
`Dskripchenko\Llm\Schema\Validator` можно использовать самостоятельно.

## Построитель запроса

| Метод | |
|---|---|
| `scenario($name)` | имя в журнале, квотах и маршрутах сценариев (по умолчанию: ключ промпта или назначение) |
| `about($model)`, `aboutRef($type, $id)` | предмет вызова (morph-ссылка) |
| `system()`, `user()`, `data()`, `history($messages)` | сообщения, собранные вручную |
| `schema($schema, $name)`, `tools($tools, $choice)` | |
| `options([...])`, `temperature()`, `maxTokens()` | |
| `idempotencyKey($key)` | успешный вызов с тем же ключом воспроизводится из журнала |
| `quota(QuotaScope)` | вместо результата резолвера |
| `mode(Mode)` | передаётся для хоста |
| `run()`, `stream(fn ($delta) => …)` | → `LlmResult` |
