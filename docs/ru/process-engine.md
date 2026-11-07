# Эффект `llm.run` для process-engine

С [dskripchenko/process-engine](https://github.com/dskripchenko/process-engine)
(и его мостом для Laravel, `dskripchenko/laravel-process-engine`) процесс
может вызывать модель как durable-эффект. Интеграция находится в
`Dskripchenko\Llm\ProcessEngine` и загружается, только когда вы её
используете; движок — рекомендуемая (suggested) зависимость.

```bash
composer require dskripchenko/laravel-process-engine
```

## Регистрация

Ничего сверх использования класса: эффект — это класс, который движок
получает из контейнера. `RunLlm` (константы `EFFECT = 'llm.run'`,
`DONE = 'llm.done'`, `FAILED = 'llm.failed'`) объявляет
`#[Idempotent(retry: new Retry(attempts: 3, initialInterval: '30 seconds', backoff: 4.0, maxInterval: '15 minutes', attemptTimeout: '10 minutes'))]`.

## Использование как действия

```php
use Dskripchenko\Llm\Mode;
use Dskripchenko\Llm\ProcessEngine\LlmRun;
use Dskripchenko\Llm\ProcessEngine\RunLlm;

final class ArticleReview extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('new', fn (Builder $s) => $s
            ->on('article.published', to: 'classifying', run: $this->prepare(...)));

        $p->state('classifying', fn (Builder $s) => $s
            ->entry(RunLlm::class)                                   // читает $context['llm']
            ->on(RunLlm::DONE, to: 'suggested', run: $this->keep(...))
            ->on(RunLlm::FAILED, to: 'manual'));
        // …
    }

    private function prepare(array &$context, array $event): void
    {
        $context['llm'] = LlmRun::prompt('article.classify', ['body' => $event['body']])
            ->about('article', $event['articleId'])
            ->mode(Mode::Suggest)
            ->tag('review')
            ->toArray();
    }

    private function keep(array &$context, array $event): void
    {
        $context['suggestion'] = $event['data'];   // проверенный структурированный ответ
    }
}
```

Запрос берётся из payload вызвавшего события, если в нём есть `prompt` или
`user`, — событие видит только `run:` перехода:

```php
->on('summarize.requested', to: 'summarizing', run: RunLlm::class)
// payload: LlmRun::ask(Purpose::Summarize, 'Summarize the discussion.', $text)->toArray()
```

иначе — из `$context['llm']` (у действия `entry()` нет события).

Используйте его как действие (`run:`, `entry()`), а не через `invoke()`:
результат приходит событиями `llm.done` / `llm.failed`. (С `invoke()` события
тоже отправляются, но дополнительно срабатывает `done.invoke.*`.)

## `LlmRun` — запрос как данные

| | |
|---|---|
| `LlmRun::prompt($key, $variables)` | пресет |
| `LlmRun::ask(Purpose, $user, $data = null, $system = null)` | вручную; `$data` становится блоком данных |
| `->scenario()`, `->about($type, $id)`, `->mode(Mode)`, `->tag()`, `->options([...])` | |
| `->toArray()` / `LlmRun::fromArray()` | |

## События

`llm.done`: `text`, `data`, `tool_calls`, `usage`, `provider`, `model`,
`call_id`, `attempts`, `finish_reason`, `replayed`, `mode`, `tag`, `key`.

`llm.failed`: `code`, `message`, `details`, `mode`, `tag`, `key`. Коды:
`unavailable`, `rejected`, `invalid_output`, `quota_exceeded`,
`cloud_not_enabled`, `no_route`, `invalid_prompt`, `invalid_request`.

## Повторы и идемпотентность

- Ключ идемпотентности — это ключ эффекта движка: экземпляр + шаг + действие
  (`{instance}/step{n}/{action}#{i}`). Обработчик передаёт его в вызов;
  успешный вызов, записанный в журнал под тем же ключом, **воспроизводится**
  из журнала (`replayed: true`) вместо повторного обращения к модели —
  например, когда воркер упал после ответа модели, но до сохранения события.
- Временные сбои (все провайдеры недоступны) выбрасываются как исключения,
  и движок повторяет попытку по политике. На последней попытке
  (`llm.effect.attempts`, держите равным числу попыток в политике) вместо
  этого отправляется `llm.failed` с кодом `unavailable`.
- Все остальные сбои сразу отправляются как `llm.failed` — при повторе они
  всё равно не пройдут.
- Прочие исключения (недоступность базы данных) передаются движку как
  неоднозначные сбои и повторяются.

## Человек в контуре: `Mode`

`Dskripchenko\Llm\Mode` — это данные, которые процесс передаёт вместе
с запросом и получает обратно в `llm.done.mode`. Больше пакет с ним ничего не
делает; сам сценарий реализуют процесс и интерфейс хоста.

| Режим | Предполагаемый сценарий |
|---|---|
| `apply` | Процесс сразу применяет ответ (`on(llm.done)` записывает его). Для малорисковых, обратимых, заметных изменений; записывайте их в историю, чтобы человек мог отменить. |
| `suggest` (по умолчанию) | Процесс сохраняет ответ как предложение рядом с предметом и идёт дальше; человек принимает, правит или отклоняет его, когда захочет. Без него ничего не меняется. |
| `approve` | Процесс ждёт в состоянии (`on('suggestion.approved')`, `on('suggestion.rejected')`, возможно `after('3 days')`), пока человек не решит; ответ применяет только одобрение. |

`Mode::appliesAutomatically()` и `Mode::waitsForHuman()` помогают ветвиться
по режиму. Ответ модели — недоверенные входные данные в любом режиме;
проверяйте его так же, как ввод человека.
