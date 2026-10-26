<?php

declare(strict_types=1);

namespace Dskripchenko\Llm;

use Dskripchenko\Llm\Logging\CallContext;
use Dskripchenko\Llm\Prompts\Data;
use Dskripchenko\Llm\Prompts\Renderer;
use Dskripchenko\Llm\Prompts\ResolvedPrompt;
use Dskripchenko\Llm\Quota\QuotaGuard;
use Dskripchenko\Llm\Quota\QuotaScope;
use Dskripchenko\Llm\Results\LlmResult;
use Dskripchenko\Llm\Transport\CallMeta;
use Dskripchenko\Llm\Transport\ChatRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * A chat request being built: from a prompt preset (`Llm::prompt()`) or by
 * hand (`Llm::request()`). Nothing is sent before `run()` or `stream()`.
 */
final class PendingRequest
{
    private ?string $scenario = null;

    private ?string $system = null;

    /** @var list<array<string, mixed>> */
    private array $history = [];

    /** @var list<array{kind: 'text'|'data', name: string, text: string, new: bool}> */
    private array $parts = [];

    /** @var array<string, mixed>|null */
    private ?array $schema = null;

    private string $schemaName = 'answer';

    /** @var list<array<string, mixed>> */
    private array $tools = [];

    private mixed $toolChoice = null;

    /** @var array<string, mixed> */
    private array $options = [];

    private ?string $subjectType = null;

    private int|string|null $subjectId = null;

    private ?string $idempotencyKey = null;

    private ?QuotaScope $quotaScope = null;

    private ?Mode $mode = null;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        private readonly Executor $executor,
        private readonly Renderer $renderer,
        private readonly QuotaGuard $quota,
        private Purpose $purpose,
        private readonly ?ResolvedPrompt $prompt = null,
        private readonly array $variables = [],
    ) {
        if ($prompt !== null) {
            $this->schema = $prompt->schema;
            $this->schemaName = self::schemaName($prompt->key);
            $this->options = $prompt->options;
        }
    }

    /** A name for this kind of call in the log and in quotas (default: the prompt key or the purpose). */
    public function scenario(string $scenario): self
    {
        $this->scenario = $scenario;

        return $this;
    }

    public function purpose(Purpose $purpose): self
    {
        $this->purpose = $purpose;

        return $this;
    }

    /** The record the call is about (logged as a morph reference). */
    public function about(Model $subject): self
    {
        $key = $subject->getKey();

        return $this->aboutRef($subject->getMorphClass(), is_int($key) || is_string($key) ? $key : null);
    }

    public function aboutRef(?string $type, int|string|null $id): self
    {
        $this->subjectType = $type;
        $this->subjectId = $id;

        return $this;
    }

    /** The system message (trusted text written by the host). Ignored for prompt presets. */
    public function system(string $text): self
    {
        $this->system = $text;

        return $this;
    }

    /** Start a user message (trusted text written by the host). */
    public function user(string $text): self
    {
        $this->parts[] = ['kind' => 'text', 'name' => '', 'text' => $text, 'new' => true];

        return $this;
    }

    /** Append untrusted user-supplied text to the current user message as a data block. */
    public function data(string $name, string $text): self
    {
        $this->parts[] = ['kind' => 'data', 'name' => $name, 'text' => $text, 'new' => false];

        return $this;
    }

    /**
     * Earlier turns of a conversation, placed between the system message and
     * the new user message. Their content is passed as is.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public function history(array $messages): self
    {
        $this->history = $messages;

        return $this;
    }

    /**
     * Ask for a structured answer matching a JSON schema.
     *
     * @param  array<string, mixed>  $schema
     */
    public function schema(array $schema, string $name = 'answer'): self
    {
        $this->schema = $schema;
        $this->schemaName = self::schemaName($name);

        return $this;
    }

    /**
     * OpenAI tool definitions. Tool calls come back in LlmResult::$toolCalls;
     * running them is the caller's business.
     *
     * @param  list<array<string, mixed>>  $tools
     */
    public function tools(array $tools, mixed $choice = null): self
    {
        $this->tools = $tools;
        $this->toolChoice = $choice;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $options  temperature, max_tokens, seed, stop…
     */
    public function options(array $options): self
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    public function temperature(float $temperature): self
    {
        return $this->options(['temperature' => $temperature]);
    }

    public function maxTokens(int $tokens): self
    {
        return $this->options(['max_tokens' => $tokens]);
    }

    /**
     * A successful call made earlier under the same key is returned instead
     * of calling the model again.
     */
    public function idempotencyKey(string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /** Count this call against these quotas instead of the resolver's. */
    public function quota(QuotaScope $scope): self
    {
        $this->quotaScope = $scope;

        return $this;
    }

    /** Carried for the host (see Mode); does not change the call. */
    public function mode(Mode $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function getMode(): ?Mode
    {
        return $this->mode;
    }

    public function run(): LlmResult
    {
        [$request, $context] = $this->build();

        return $this->executor->chat($request, $context);
    }

    /**
     * Stream the answer: `$onDelta` receives text as it arrives; the full
     * result is returned at the end. With a schema, an invalid answer is
     * retried and streamed again.
     *
     * @param  callable(string): void  $onDelta
     */
    public function stream(callable $onDelta): LlmResult
    {
        [$request, $context] = $this->build();

        return $this->executor->chat($request, $context, $onDelta);
    }

    /**
     * The request as it would be sent (model left empty: the route decides).
     */
    public function toChatRequest(): ChatRequest
    {
        return $this->build()[0];
    }

    /**
     * @return array{ChatRequest, CallContext}
     */
    private function build(): array
    {
        $boundary = Data::boundary();
        $system = $this->system;
        $messages = [];
        $hasData = false;

        if ($this->prompt !== null) {
            $rendered = $this->renderer->render($this->prompt, $this->variables, $boundary);
            $system = $rendered['system'];
            $hasData = $rendered['hasData'];
            $messages[] = ['role' => 'user', 'content' => $rendered['user']];
        }

        $current = null;
        foreach ($this->parts as $part) {
            if ($part['kind'] === 'data') {
                $hasData = true;
                $text = Data::wrap($part['name'], $part['text'], $boundary);
            } else {
                $text = $part['text'];
            }
            if ($part['new'] || $current === null) {
                if ($current !== null) {
                    $messages[] = ['role' => 'user', 'content' => $current];
                }
                $current = $text;
            } else {
                $current .= "\n\n".$text;
            }
        }
        if ($current !== null) {
            $messages[] = ['role' => 'user', 'content' => $current];
        }

        $instructions = array_values(array_filter([
            $system,
            $hasData ? Data::instruction($boundary) : null,
            $this->schema !== null
                ? "Answer with a single JSON value that matches this JSON schema, with no prose and no Markdown:\n"
                    .json_encode($this->schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
        ], fn (?string $s) => $s !== null && $s !== ''));

        $all = [];
        if ($instructions !== []) {
            $all[] = ['role' => 'system', 'content' => implode("\n\n", $instructions)];
        }
        array_push($all, ...$this->history, ...$messages);

        $meta = new CallMeta($this->purpose, $this->scenario ?? $this->prompt->key ?? $this->purpose->value, $this->prompt?->key);
        $request = new ChatRequest($meta, '', $all, $this->options, $this->tools, $this->toolChoice, $this->schema, $this->schemaName);
        $context = new CallContext(
            meta: $meta,
            quota: $this->quotaScope ?? $this->quota->scopeFor($meta, $this->subjectType, $this->subjectId),
            subjectType: $this->subjectType,
            subjectId: $this->subjectId,
            idempotencyKey: $this->idempotencyKey,
            promptVersion: $this->prompt?->version,
        );

        return [$request, $context];
    }

    private static function schemaName(string $name): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $name);

        return $clean === '' ? 'answer' : substr($clean, 0, 64);
    }
}
