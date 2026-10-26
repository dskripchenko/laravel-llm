<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Quota;

/**
 * Which daily token budgets a call counts against. Returned by the host's
 * resolver; a null key is not limited. Limits default to
 * `llm.quota.*_tokens_per_day`.
 */
final class QuotaScope
{
    public function __construct(
        public readonly ?string $tenantKey = null,
        public readonly ?string $projectKey = null,
        public readonly ?int $tenantLimit = null,
        public readonly ?int $projectLimit = null,
    ) {}
}
