<?php

declare(strict_types=1);

return [

    /*
    | HTTP defaults for every provider. A provider's `limits` may override
    | `timeout` (seconds) per row.
    */
    // Load the package migrations automatically. A multi-tenant host that
    // keeps these tables in tenant schemas turns this off and publishes them
    // (`php artisan vendor:publish --tag=llm-migrations`) into its tenant path.
    'run_migrations' => env('LLM_RUN_MIGRATIONS', true),

    'http' => [
        'timeout' => env('LLM_TIMEOUT', 120),
        'connect_timeout' => env('LLM_CONNECT_TIMEOUT', 5),
    ],

    /*
    | Structured output: how many times a model is asked in total when its
    | answer does not match the JSON schema (the validation errors are fed
    | back each time).
    */
    'structured' => [
        'max_attempts' => env('LLM_STRUCTURED_MAX_ATTEMPTS', 3),
    ],

    /*
    | Prompt presets in code: classes extending Dskripchenko\Llm\Prompts\Prompt.
    | Tenant overrides live in the `llm_prompt_templates` table.
    */
    'prompts' => [
        'presets' => [],
    ],

    /*
    | The call log. Prompt and response bodies are kept for `keep_bodies_days`
    | and then pruned to metrics by `llm:prune`. With `schedule_prune` the
    | package schedules `llm:prune` daily itself. `bodies` false keeps no
    | bodies at all: only metrics, the subject and the status — for an
    | application whose prompts carry what must not be stored.
    */
    'log' => [
        'enabled' => true,
        'bodies' => env('LLM_LOG_BODIES', true),
        'keep_bodies_days' => env('LLM_KEEP_BODIES_DAYS', 30),
        'schedule_prune' => true,
    ],

    /*
    | Attribution: whom a call is made for (`onBehalfOf()`) and the project,
    | task and run it belongs to (`attribute()`). Always logged; sent as these
    | headers only to providers with `attribution` set — a gateway that keeps
    | accounts by them. A name left empty is not sent.
    */
    'attribution' => [
        'headers' => [
            'on_behalf_of' => env('LLM_HEADER_ON_BEHALF_OF', 'X-On-Behalf-Of'),
            'project' => env('LLM_HEADER_PROJECT', 'X-Project'),
            'task' => env('LLM_HEADER_TASK', 'X-Task'),
            'run' => env('LLM_HEADER_RUN', 'X-Run'),
        ],
    ],

    /*
    | Daily token limits. The host tells which keys a call counts against
    | with Llm::resolveQuotaUsing(); a QuotaScope may carry its own limits,
    | these are the defaults. null = unlimited.
    */
    'quota' => [
        'tenant_tokens_per_day' => env('LLM_TENANT_TOKENS_PER_DAY'),
        'project_tokens_per_day' => env('LLM_PROJECT_TOKENS_PER_DAY'),
    ],

    /*
    | The process-engine effect (Dskripchenko\Llm\ProcessEngine\RunLlm).
    */
    'effect' => [
        // Must match the attempts of the effect's retry policy: on the last
        // attempt a transient failure is reported as `llm.failed` instead
        // of being thrown for another retry.
        'attempts' => 3,
    ],

];
