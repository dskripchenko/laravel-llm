<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Prompts;

use Dskripchenko\Llm\Exceptions\InvalidPrompt;
use Stringable;

/**
 * Fills `{{ name }}` placeholders from a typed context.
 */
final class Renderer
{
    public const PLACEHOLDER = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

    private const TYPES = ['string', 'int', 'float', 'bool', 'list', 'json', 'data'];

    /**
     * @param  array<string, mixed>  $values
     * @return array{system: string|null, user: string, hasData: bool}
     */
    public function render(ResolvedPrompt $prompt, array $values, string $boundary): array
    {
        $problems = self::check($prompt->variables, [$prompt->system ?? '', $prompt->user]);
        $rendered = [];
        $hasData = false;

        foreach ($prompt->variables as $name => $declared) {
            $optional = str_starts_with($declared, '?');
            $type = ltrim($declared, '?');
            $value = $values[$name] ?? null;
            if ($value === null) {
                if (! $optional) {
                    $problems[] = "variable `{$name}` is required";
                }
                $rendered[$name] = '';

                continue;
            }
            $text = $this->format($type, $value);
            if ($text === null) {
                $problems[] = "variable `{$name}` must be {$type}, got ".get_debug_type($value);

                continue;
            }
            if ($type === 'data') {
                $hasData = true;
                $text = Data::wrap($name, $text, $boundary);
            }
            $rendered[$name] = $text;
        }
        foreach (array_keys($values) as $name) {
            if (! array_key_exists($name, $prompt->variables)) {
                $problems[] = "variable `{$name}` is not declared by the preset";
            }
        }
        if ($problems !== []) {
            throw new InvalidPrompt($prompt->key, array_values(array_unique($problems)));
        }

        $fill = fn (string $template): string => (string) preg_replace_callback(
            self::PLACEHOLDER,
            fn (array $m) => $rendered[$m[1]] ?? '',
            $template,
        );

        return [
            'system' => $prompt->system !== null ? $fill($prompt->system) : null,
            'user' => $fill($prompt->user),
            'hasData' => $hasData,
        ];
    }

    /**
     * Problems of templates against declared variables: unknown types and
     * placeholders that are not declared.
     *
     * @param  array<string, string>  $variables
     * @param  list<string>  $templates
     * @return list<string>
     */
    public static function check(array $variables, array $templates): array
    {
        $problems = [];
        foreach ($variables as $name => $declared) {
            if (! in_array(ltrim($declared, '?'), self::TYPES, true)) {
                $problems[] = "variable `{$name}` has an unknown type `{$declared}`";
            }
        }
        foreach ($templates as $template) {
            preg_match_all(self::PLACEHOLDER, $template, $m);
            foreach ($m[1] as $name) {
                if (! array_key_exists($name, $variables)) {
                    $problems[] = "placeholder `{{ {$name} }}` is not a declared variable";
                }
            }
        }

        return $problems;
    }

    private function format(string $type, mixed $value): ?string
    {
        return match ($type) {
            'string', 'data' => is_string($value) || $value instanceof Stringable ? (string) $value : null,
            'int' => is_int($value) ? (string) $value : null,
            'float' => is_int($value) || is_float($value) ? (string) $value : null,
            'bool' => is_bool($value) ? ($value ? 'true' : 'false') : null,
            'list' => is_array($value) && array_is_list($value)
                ? implode("\n", array_map(fn ($item) => '- '.(is_scalar($item) ? (string) $item : (string) json_encode($item, JSON_UNESCAPED_UNICODE)), $value))
                : null,
            'json' => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => null,
        };
    }
}
