<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An OpenAI-compatible endpoint (Ollama, vLLM, LM Studio, llama.cpp server,
 * or a cloud API). Lives on the host's default connection.
 *
 * A cloud provider (`is_cloud`) is routed to only after an administrator
 * has set `cloud_enabled`: prompts carry the tenant's data out of its
 * infrastructure.
 *
 * @property int $id
 * @property string $name
 * @property string $base_url
 * @property string|null $api_key
 * @property list<string>|null $models
 * @property array<string, mixed>|null $limits
 * @property bool $is_cloud
 * @property bool $cloud_enabled
 * @property bool $is_enabled
 * @property bool $attribution Takes the call's attribution as headers (a gateway with accounting of its own)
 * @property array<string, array<string, mixed>>|null $prices Per model (or `*` for any), per million tokens: `input`, `output`
 */
class Provider extends Model
{
    protected $table = 'llm_providers';

    protected $guarded = ['id'];

    protected $hidden = ['api_key'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_cloud' => false,
        'cloud_enabled' => false,
        'is_enabled' => true,
        'attribution' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'models' => 'array',
            'limits' => 'array',
            'is_cloud' => 'boolean',
            'cloud_enabled' => 'boolean',
            'is_enabled' => 'boolean',
            'attribution' => 'boolean',
            'prices' => 'array',
        ];
    }

    /**
     * @return HasMany<Route, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(Route::class, 'provider_id');
    }

    /** A cloud provider that an administrator has not explicitly enabled. */
    public function isBlockedCloud(): bool
    {
        return $this->is_cloud && ! $this->cloud_enabled;
    }

    public function limit(string $key, mixed $default = null): mixed
    {
        return ($this->limits ?? [])[$key] ?? $default;
    }

    /**
     * What the usage costs by this provider's prices: the model's own, else
     * those under `*`; null when neither is set.
     */
    public function cost(string $model, int $promptTokens, int $completionTokens): ?float
    {
        $prices = $this->prices ?? [];
        $price = $prices[$model] ?? $prices['*'] ?? null;
        if (! is_array($price)) {
            return null;
        }
        $input = $price['input'] ?? 0;
        $output = $price['output'] ?? 0;

        return round(((is_numeric($input) ? (float) $input : 0.0) * $promptTokens + (is_numeric($output) ? (float) $output : 0.0) * $completionTokens) / 1_000_000, 8);
    }

    /** The base URL without a trailing slash, e.g. `http://127.0.0.1:11434/v1`. */
    public function endpoint(string $path): string
    {
        return rtrim($this->base_url, '/').'/'.ltrim($path, '/');
    }
}
