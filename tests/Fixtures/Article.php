<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Article extends Model
{
    protected $table = 'articles';

    protected $guarded = [];
}
