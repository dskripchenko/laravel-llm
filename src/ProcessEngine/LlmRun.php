<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\ProcessEngine;

use Dskripchenko\Llm\LlmManager;
use Dskripchenko\Llm\Mode;
use Dskripchenko\Llm\PendingRequest;
use Dskripchenko\Llm\Purpose;
use InvalidArgumentException;

/**
 * What an `llm.run` effect should do, as plain data that fits into a
 * process context or an event payload. Build it in a process method and
 * store `toArray()` under `$context['llm']`, or send it as the payload of
 * the event whose transition runs the effect.
 */
final class LlmRun
{
    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $options
     */
    private function __construct(
        public readonly ?string $prompt = null,
        public readonly array $variables = [],
        public readonly ?Purpose $purpose = null,
        public readonly ?string $system = null,
        public readonly ?string $user = null,
        public readonly ?string $data = null,
        public readonly ?string $scenario = null,
        public readonly ?string $subjectType = null,
        public readonly int|string|null $subjectId = null,
        public readonly Mode $mode = Mode::Suggest,
        public readonly ?string $tag = null,
        public readonly array $options = [],
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     */
    public static function prompt(string $key, array $variables = []): self
    {
        return new self(prompt: $key, variables: $variables);
    }

    /**
     * A hand-written request: trusted instructions plus untrusted data.
     */
    public static function ask(Purpose $purpose, string $user, ?string $data = null, ?string $system = null): self
    {
        return new self(purpose: $purpose, system: $system, user: $user, data: $data);
    }

    public function scenario(string $scenario): self
    {
        return $this->with(scenario: $scenario);
    }

    public function about(string $type, int|string $id): self
    {
        return $this->with(subjectType: $type, subjectId: $id);
    }

    public function mode(Mode $mode): self
    {
        return $this->with(mode: $mode);
    }

    /** Echoed in `llm.done` / `llm.failed`, to tell parallel calls apart. */
    public function tag(string $tag): self
    {
        return $this->with(tag: $tag);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function options(array $options): self
    {
        return $this->with(options: $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'prompt' => $this->prompt,
            'variables' => $this->variables,
            'purpose' => $this->purpose?->value,
            'system' => $this->system,
            'user' => $this->user,
            'data' => $this->data,
            'scenario' => $this->scenario,
            'subject' => $this->subjectType !== null ? ['type' => $this->subjectType, 'id' => $this->subjectId] : null,
            'mode' => $this->mode->value,
            'tag' => $this->tag,
            'options' => $this->options,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $prompt = $data['prompt'] ?? null;
        $user = $data['user'] ?? null;
        if (! is_string($prompt) && ! is_string($user)) {
            throw new InvalidArgumentException('An llm.run request needs `prompt` (a preset key) or `user` (a message)');
        }
        $subject = is_array($data['subject'] ?? null) ? $data['subject'] : [];
        $subjectId = $subject['id'] ?? null;

        return new self(
            prompt: is_string($prompt) ? $prompt : null,
            variables: self::map($data['variables'] ?? []),
            purpose: is_string($data['purpose'] ?? null) ? Purpose::from($data['purpose']) : null,
            system: self::str($data['system'] ?? null),
            user: is_string($user) ? $user : null,
            data: self::str($data['data'] ?? null),
            scenario: self::str($data['scenario'] ?? null),
            subjectType: self::str($subject['type'] ?? null),
            subjectId: is_int($subjectId) || is_string($subjectId) ? $subjectId : null,
            mode: is_string($data['mode'] ?? null) ? Mode::from($data['mode']) : Mode::Suggest,
            tag: self::str($data['tag'] ?? null),
            options: self::map($data['options'] ?? []),
        );
    }

    public function toPending(LlmManager $llm): PendingRequest
    {
        if ($this->prompt !== null) {
            $pending = $llm->prompt($this->prompt, $this->variables);
            if ($this->purpose !== null) {
                $pending->purpose($this->purpose);
            }
        } else {
            $pending = $llm->request($this->purpose ?? Purpose::Chat)->user((string) $this->user);
            if ($this->system !== null) {
                $pending->system($this->system);
            }
            if ($this->data !== null) {
                $pending->data('input', $this->data);
            }
        }
        if ($this->scenario !== null) {
            $pending->scenario($this->scenario);
        }
        if ($this->subjectType !== null) {
            $pending->aboutRef($this->subjectType, $this->subjectId);
        }
        if ($this->options !== []) {
            $pending->options($this->options);
        }

        return $pending->mode($this->mode);
    }

    /**
     * @param  array<string, mixed>|null  $options
     */
    private function with(
        ?string $scenario = null,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        ?Mode $mode = null,
        ?string $tag = null,
        ?array $options = null,
    ): self {
        return new self(
            prompt: $this->prompt,
            variables: $this->variables,
            purpose: $this->purpose,
            system: $this->system,
            user: $this->user,
            data: $this->data,
            scenario: $scenario ?? $this->scenario,
            subjectType: $subjectType ?? $this->subjectType,
            subjectId: $subjectId ?? $this->subjectId,
            mode: $mode ?? $this->mode,
            tag: $tag ?? $this->tag,
            options: $options ?? $this->options,
        );
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
