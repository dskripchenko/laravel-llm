<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Prompts;

use Dskripchenko\Llm\Exceptions\InvalidPrompt;
use Dskripchenko\Llm\Models\PromptTemplate;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;

/**
 * Presets in code plus the tenant's versioned overrides in the database.
 */
final class PromptRepository
{
    /** @var array<string, class-string<Prompt>|Prompt> */
    private array $presets = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<Prompt>|Prompt  $prompt
     */
    public function register(string|Prompt $prompt): Prompt
    {
        $instance = $this->instance($prompt);
        $problems = Renderer::check($instance->variables(), [$instance->system() ?? '', $instance->user()]);
        if ($problems !== []) {
            throw new InvalidPrompt($instance->key(), $problems);
        }
        $this->presets[$instance->key()] = $prompt;

        return $instance;
    }

    /**
     * @param  string|class-string<Prompt>|Prompt  $prompt  a key, a preset class or an instance
     */
    public function preset(string|Prompt $prompt): Prompt
    {
        if ($prompt instanceof Prompt) {
            return $prompt;
        }
        if (isset($this->presets[$prompt])) {
            return $this->instance($this->presets[$prompt]);
        }
        if (class_exists($prompt) && is_subclass_of($prompt, Prompt::class)) {
            return $this->register($prompt);
        }

        throw new InvalidPrompt($prompt, ['no such preset (register it in llm.prompts.presets)']);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->presets);
    }

    /**
     * @param  string|class-string<Prompt>|Prompt  $prompt
     */
    public function resolve(string|Prompt $prompt): ResolvedPrompt
    {
        $preset = $this->preset($prompt);
        $query = PromptTemplate::query()->where('key', $preset->key())->where('is_active', true);
        $query->orderByDesc('version');
        $override = $query->first();

        return new ResolvedPrompt(
            key: $preset->key(),
            version: $override?->version,
            purpose: $preset->purpose(),
            system: $override !== null ? $override->system : $preset->system(),
            user: $override !== null ? $override->user : $preset->user(),
            variables: $preset->variables(),
            schema: $override !== null && $override->schema !== null ? $override->schema : $preset->schema(),
            options: $preset->options(),
        );
    }

    /**
     * Store a new version of a tenant override (and make it the active one,
     * unless `$activate` is false). The templates are checked against the
     * preset's variables first.
     *
     * @param  array<string, mixed>|null  $schema  null keeps the preset's schema
     */
    public function override(string $key, string $user, ?string $system = null, ?array $schema = null, ?string $note = null, bool $activate = true): PromptTemplate
    {
        $preset = $this->preset($key);
        $problems = Renderer::check($preset->variables(), [$system ?? '', $user]);
        if ($problems !== []) {
            throw new InvalidPrompt($key, $problems);
        }

        return (new PromptTemplate)->getConnection()->transaction(function () use ($preset, $user, $system, $schema, $note, $activate): PromptTemplate {
            $key = $preset->key();
            $query = PromptTemplate::query()->where('key', $key);
            $query->lockForUpdate();
            $max = $query->max('version');
            $version = (is_numeric($max) ? (int) $max : 0) + 1;
            if ($activate) {
                PromptTemplate::query()->where('key', $key)->update(['is_active' => false]);
            }

            return PromptTemplate::query()->create([
                'key' => $key,
                'version' => $version,
                'system' => $system,
                'user' => $user,
                'schema' => $schema,
                'is_active' => $activate,
                'note' => $note,
            ]);
        });
    }

    /** Make an earlier (or a draft) version the active one. */
    public function activate(string $key, int $version): void
    {
        (new PromptTemplate)->getConnection()->transaction(function () use ($key, $version): void {
            $template = PromptTemplate::query()->where('key', $key)->where('version', $version)->first();
            if ($template === null) {
                throw new InvalidPrompt($key, ["version {$version} does not exist"]);
            }
            PromptTemplate::query()->where('key', $key)->update(['is_active' => false]);
            $template->forceFill(['is_active' => true])->save();
        });
    }

    /** Back to the preset in code; the versions stay for history. */
    public function revert(string $key): void
    {
        PromptTemplate::query()->where('key', $key)->update(['is_active' => false]);
    }

    /**
     * @return Collection<int, PromptTemplate>
     */
    public function versions(string $key): Collection
    {
        $query = PromptTemplate::query()->where('key', $key);
        $query->orderBy('version');

        return $query->get();
    }

    /**
     * @param  class-string<Prompt>|Prompt  $prompt
     */
    private function instance(string|Prompt $prompt): Prompt
    {
        if ($prompt instanceof Prompt) {
            return $prompt;
        }

        return $this->container->make($prompt);
    }
}
