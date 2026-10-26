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
    | package schedules `llm:prune` daily itself.
    */
    'log' => [
        'enabled' => true,
        'keep_bodies_days' => env('LLM_KEEP_BODIES_DAYS', 30),
        'schedule_prune' => true,
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
