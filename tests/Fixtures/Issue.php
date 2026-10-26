<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Issue extends Model
{
    protected $table = 'issues';

    protected $guarded = [];
}
