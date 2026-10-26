<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Schema;

use stdClass;

/**
 * A small JSON Schema subset, enough to check a model's structured answer:
 * `type` (one or a list), `properties`, `required`, `additionalProperties`
 * (false), `enum`, `const`, `items`, `minItems`/`maxItems`,
 * `minimum`/`maximum`, `minLength`/`maxLength`. Unknown keywords are ignored.
 *
 * Validate a value decoded with objects as stdClass (`json_decode($s)`), so
 * that `{}` and `[]` stay distinguishable.
 */
final class Validator
{
    /**
     * @param  array<mixed>  $schema
     * @return list<string> errors as "path: message"; empty when valid
     */
    public function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];

        if (array_key_exists('type', $schema)) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            $types = array_values(array_filter($types, 'is_string'));
            if ($types !== [] && ! $this->matchesAny($value, $types)) {
                return ["{$path}: expected ".implode(' or ', $types).', got '.$this->typeOf($value)];
            }
        }

        if (array_key_exists('const', $schema) && ! $this->equals($value, $schema['const'])) {
            $errors[] = "{$path}: must be ".$this->show($schema['const']);
        }

        if (is_array($schema['enum'] ?? null)) {
            $found = false;
            foreach ($schema['enum'] as $allowed) {
                if ($this->equals($value, $allowed)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $errors[] = "{$path}: must be one of ".implode(', ', array_map(fn ($v) => $this->show($v), $schema['enum'])).', got '.$this->show($value);
            }
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            if (is_int($schema['minLength'] ?? null) && $length < $schema['minLength']) {
                $errors[] = "{$path}: must be at least {$schema['minLength']} characters long";
            }
            if (is_int($schema['maxLength'] ?? null) && $length > $schema['maxLength']) {
                $errors[] = "{$path}: must be at most {$schema['maxLength']} characters long";
            }
        }

        if ((is_int($value) || is_float($value))) {
            if (is_numeric($schema['minimum'] ?? null) && $value < $schema['minimum']) {
                $errors[] = "{$path}: must be >= {$schema['minimum']}";
            }
            if (is_numeric($schema['maximum'] ?? null) && $value > $schema['maximum']) {
                $errors[] = "{$path}: must be <= {$schema['maximum']}";
            }
        }

        if ($value instanceof stdClass) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $object = get_object_vars($value);
            foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $required) {
                if (is_string($required) && ! array_key_exists($required, $object)) {
                    $errors[] = "{$path}: missing required property `{$required}`";
                }
            }
            foreach ($object as $name => $item) {
                $name = (string) $name;
                if (is_array($properties[$name] ?? null)) {
                    array_push($errors, ...$this->validate($item, $properties[$name], $path.'.'.$name));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}: unexpected property `{$name}`";
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    array_push($errors, ...$this->validate($item, $schema['additionalProperties'], $path.'.'.$name));
                }
            }
        }

        if (is_array($value) && array_is_list($value)) {
            $count = count($value);
            if (is_int($schema['minItems'] ?? null) && $count < $schema['minItems']) {
                $errors[] = "{$path}: must have at least {$schema['minItems']} item(s)";
            }
            if (is_int($schema['maxItems'] ?? null) && $count > $schema['maxItems']) {
                $errors[] = "{$path}: must have at most {$schema['maxItems']} item(s)";
            }
            if (is_array($schema['items'] ?? null)) {
                foreach ($value as $i => $item) {
                    array_push($errors, ...$this->validate($item, $schema['items'], "{$path}[{$i}]"));
                }
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $types
     */
    private function matchesAny(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            if ($this->matches($value, $type)) {
                return true;
            }
        }

        return false;
    }

    private function matches(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => $value instanceof stdClass,
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }

    private function typeOf(mixed $value): string
    {
        return match (true) {
            $value instanceof stdClass => 'object',
            is_array($value) => 'array',
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            default => get_debug_type($value),
        };
    }

    private function equals(mixed $value, mixed $expected): bool
    {
        if ((is_int($value) || is_float($value)) && (is_int($expected) || is_float($expected))) {
            return $value == $expected;
        }

        return json_encode($value) === json_encode($expected);
    }

    private function show(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
