<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Transport;

/**
 * Whose the call is: the person or the agent it is made on behalf of, and
 * the project, the task and the run it belongs to. Logged with the call;
 * sent as headers only to a provider that takes them (`attribution`), such
 * as a gateway that keeps its own accounting — never to the model itself.
 */
final class Attribution
{
    public readonly ?string $onBehalfOf;

    public readonly ?string $project;

    public readonly ?string $task;

    public readonly ?string $run;

    public function __construct(?string $onBehalfOf = null, ?string $project = null, ?string $task = null, ?string $run = null)
    {
        $this->onBehalfOf = self::clean($onBehalfOf, 128);
        $this->project = self::clean($project, 255);
        $this->task = self::clean($task, 128);
        $this->run = self::clean($run, 128);
    }

    public function isEmpty(): bool
    {
        return $this->onBehalfOf === null && $this->project === null && $this->task === null && $this->run === null;
    }

    /** The same, with what is given here taking the place of what was. */
    public function merge(self $other): self
    {
        return new self(
            $other->onBehalfOf ?? $this->onBehalfOf,
            $other->project ?? $this->project,
            $other->task ?? $this->task,
            $other->run ?? $this->run,
        );
    }

    /**
     * The headers of the given names (`llm.attribution.headers`) for what is set.
     *
     * @param  array<mixed>  $names  on_behalf_of, project, task, run => header name
     * @return array<string, string>
     */
    public function headers(array $names): array
    {
        $headers = [];
        foreach (['on_behalf_of' => $this->onBehalfOf, 'project' => $this->project, 'task' => $this->task, 'run' => $this->run] as $key => $value) {
            $name = $names[$key] ?? null;
            if ($value !== null && is_string($name) && $name !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * @return array{project?: string, task?: string, run?: string}
     */
    public function work(): array
    {
        return array_filter(['project' => $this->project, 'task' => $this->task, 'run' => $this->run], static fn (?string $v): bool => $v !== null);
    }

    /** Trimmed, one line (a header cannot carry a line break), at most `$max` characters; empty — none. */
    private static function clean(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
