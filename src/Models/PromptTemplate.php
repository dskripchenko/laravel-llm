<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's override of a prompt preset. Versions only grow; at most one
 * version of a key is active, and with none active the preset in code is
 * used.
 *
 * @property int $id
 * @property string $key
 * @property int $version
 * @property string|null $system
 * @property string $user
 * @property array<string, mixed>|null $schema
 * @property bool $is_active
 * @property string|null $note
 */
class PromptTemplate extends Model
{
    protected $table = 'llm_prompt_templates';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'schema' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
