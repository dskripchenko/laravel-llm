<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Prompts;

use Dskripchenko\Llm\Purpose;

/**
 * A prompt preset in code. Tenants may override `system()`, `user()` and
 * `schema()` with versioned rows; the variables, purpose and options stay
 * those of the preset.
 *
 * Templates refer to variables as `{{ name }}`. Variable types:
 *
 * | Type     | Rendered as |
 * |----------|-------------|
 * | `string` | as is (text written by the host, trusted) |
 * | `int`, `float`, `bool` | their string form |
 * | `list`   | one `- item` line per element |
 * | `json`   | JSON |
 * | `data`   | a delimited block of untrusted user text (see Data) |
 *
 * A `?` prefix (`?string`) makes a variable optional (null or absent → empty).
 * Anything a user typed — an issue description, a comment, a page — must be
 * `data`, never `string`.
 */
abstract class Prompt
{
    /** A stable key, e.g. `issue.summary`. Overrides are stored under it. */
    abstract public function key(): string;

    /** The user message template. */
    abstract public function user(): string;

    /** The system message template. */
    public function system(): ?string
    {
        return null;
    }

    public function purpose(): Purpose
    {
        return Purpose::Chat;
    }

    /**
     * @return array<string, string> name → type
     */
    public function variables(): array
    {
        return [];
    }

    /**
     * The JSON schema of a structured answer; null for free text.
     *
     * @return array<string, mixed>|null
     */
    public function schema(): ?array
    {
        return null;
    }

    /**
     * Request options: temperature, max_tokens, seed…
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return [];
    }
}
