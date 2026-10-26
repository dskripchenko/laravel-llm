<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Logging;

use Dskripchenko\Llm\Quota\QuotaScope;
use Dskripchenko\Llm\Transport\CallMeta;

/**
 * What every log row of one logical request shares.
 */
final class CallContext
{
    public function __construct(
        public readonly CallMeta $meta,
        public readonly QuotaScope $quota,
        public readonly ?string $subjectType = null,
        public readonly int|string|null $subjectId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?int $promptVersion = null,
    ) {}
}
