<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Logging;

use Dskripchenko\Llm\CallStatus;
use Dskripchenko\Llm\Models\Call;
use Dskripchenko\Llm\Routing\Candidate;
use Dskripchenko\Llm\Transport\Usage;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Writes one `llm_calls` row per HTTP call.
 */
final class CallLogger
{
    public function __construct(private readonly Config $config) {}

    /** Whether prompts, responses and results are kept; false — metrics only (`llm.log.bodies`). */
    public function keepsBodies(): bool
    {
        return filter_var($this->config->get('llm.log.bodies', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function record(
        CallContext $context,
        Candidate $candidate,
        CallStatus $status,
        int $attempt,
        int $durationMs,
        Usage $usage = new Usage,
        ?string $request = null,
        ?string $response = null,
        ?array $result = null,
        ?string $error = null,
    ): ?Call {
        if (! $this->config->get('llm.log.enabled', true)) {
            return null;
        }
        $bodies = $this->keepsBodies();

        return Call::query()->create([
            'scenario' => $context->meta->scenario,
            'purpose' => $context->meta->purpose,
            'prompt_key' => $context->meta->promptKey,
            'prompt_version' => $context->promptVersion,
            'provider_id' => $candidate->provider->exists ? $candidate->provider->getKey() : null,
            'provider' => $candidate->provider->name,
            'model' => $candidate->model,
            'attempt' => $attempt,
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'total_tokens' => $usage->total(),
            'duration_ms' => $durationMs,
            'status' => $status,
            'error' => $error !== null ? mb_substr($error, 0, 2000) : null,
            'subject_type' => $context->subjectType,
            'subject_id' => $context->subjectId,
            'tenant_key' => $context->quota->tenantKey,
            'project_key' => $context->quota->projectKey,
            'idempotency_key' => $context->idempotencyKey,
            'request' => $bodies ? $request : null,
            'response' => $bodies ? $response : null,
            'result' => $bodies ? $result : null,
            'bodies_pruned_at' => $bodies ? null : now(),
        ]);
    }
}
