<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Models;

use Dskripchenko\Llm\CallStatus;
use Dskripchenko\Llm\Purpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One HTTP call to one provider. Bodies (`request`, `response`, `result`)
 * are pruned after `llm.log.keep_bodies_days`; the metrics stay.
 *
 * @property int $id
 * @property string $scenario
 * @property Purpose $purpose
 * @property string|null $prompt_key
 * @property int|null $prompt_version
 * @property int|null $provider_id
 * @property string $provider
 * @property string $model
 * @property int $attempt
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $total_tokens
 * @property int $duration_ms
 * @property CallStatus $status
 * @property string|null $error
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property string|null $tenant_key
 * @property string|null $project_key
 * @property string|null $idempotency_key
 * @property string|null $on_behalf_of Whom the call was made for (Attribution)
 * @property array{project?: string, task?: string, run?: string}|null $attribution
 * @property float|null $cost From the provider's answer, else by its prices; null — not known
 * @property string|null $request
 * @property string|null $response
 * @property array<string, mixed>|null $result
 * @property Carbon|null $bodies_pruned_at
 * @property Carbon|null $created_at
 */
class Call extends Model
{
    protected $table = 'llm_calls';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => Purpose::class,
            'status' => CallStatus::class,
            'result' => 'array',
            'attribution' => 'array',
            'cost' => 'float',
            'bodies_pruned_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
