<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Models;

use Dskripchenko\Llm\Purpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Purpose (optionally narrowed to a scenario) → provider + model, with a
 * fallback route tried when this one is unavailable.
 *
 * @property int $id
 * @property Purpose $purpose
 * @property string|null $scenario
 * @property int $provider_id
 * @property string $model
 * @property int|null $fallback_route_id
 * @property array<string, mixed>|null $options
 * @property bool $is_active
 * @property-read Provider|null $provider
 * @property-read Route|null $fallback
 */
class Route extends Model
{
    protected $table = 'llm_routes';

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => Purpose::class,
            'options' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }

    /**
     * @return BelongsTo<Route, $this>
     */
    public function fallback(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'fallback_route_id');
    }
}
