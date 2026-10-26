<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Schema;

/**
 * Pulls the JSON value out of a model's answer. Local models often wrap it
 * in a Markdown fence or prefix a `<think>` block even when asked for JSON.
 */
final class JsonOutput
{
    /**
     * @return array{ok: bool, value: mixed, error: string|null} `value` decoded with objects as stdClass
     */
    public static function parse(string $text): array
    {
        $text = trim((string) preg_replace('~<think>.*?</think>~s', '', $text));
        if (preg_match('~^```(?:json|JSON)?\s*\n(.*?)\n?```\s*$~s', $text, $m) === 1) {
            $text = trim($m[1]);
        }
        if ($text === '') {
            return ['ok' => false, 'value' => null, 'error' => '$: the answer is empty, a JSON value is required'];
        }

        $value = json_decode($text);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // A JSON object surrounded by prose: take the outermost braces.
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $value = json_decode(substr($text, $start, $end - $start + 1));
            }
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['ok' => false, 'value' => null, 'error' => '$: the answer is not valid JSON ('.json_last_error_msg().')'];
            }
        }

        return ['ok' => true, 'value' => $value, 'error' => null];
    }

    /** stdClass trees → associative arrays. */
    public static function toArray(mixed $value): mixed
    {
        return json_decode((string) json_encode($value), true);
    }
}
