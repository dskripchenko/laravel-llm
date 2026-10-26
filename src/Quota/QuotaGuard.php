<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Quota;

use Closure;
use Dskripchenko\Llm\Exceptions\QuotaExceeded;
use Dskripchenko\Llm\Models\Call;
use Dskripchenko\Llm\Transport\CallMeta;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;

/**
 * Daily token quotas, counted from the call log (the metrics survive
 * pruning). The check runs before every call against tokens already used:
 * the call that crosses the limit is allowed, the next one is not.
 */
final class QuotaGuard
{
    /** @var (Closure(CallMeta, ?string, int|string|null): ?QuotaScope)|null */
    private ?Closure $resolver = null;

    public function __construct(private readonly Config $config) {}

    /**
     * @param  Closure(CallMeta, ?string, int|string|null): ?QuotaScope  $resolver  (meta, subject type, subject id)
     */
    public function resolveUsing(?Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function scopeFor(CallMeta $meta, ?string $subjectType = null, int|string|null $subjectId = null): QuotaScope
    {
        return ($this->resolver !== null ? ($this->resolver)($meta, $subjectType, $subjectId) : null) ?? new QuotaScope;
    }

    /**
     * @throws QuotaExceeded
     */
    public function check(QuotaScope $scope): void
    {
        $this->checkOne('tenant', 'tenant_key', $scope->tenantKey, $scope->tenantLimit ?? $this->default('tenant_tokens_per_day'));
        $this->checkOne('project', 'project_key', $scope->projectKey, $scope->projectLimit ?? $this->default('project_tokens_per_day'));
    }

    /** Tokens a key has used today. */
    public function used(string $column, string $key): int
    {
        $sum = Call::query()->where($column, $key)->where('created_at', '>=', Carbon::now()->startOfDay())->sum('total_tokens');

        return is_numeric($sum) ? (int) $sum : 0;
    }

    /**
     * @param  'tenant'|'project'  $scope
     */
    private function checkOne(string $scope, string $column, ?string $key, ?int $limit): void
    {
        if ($key === null || $limit === null) {
            return;
        }
        $used = $this->used($column, $key);
        if ($used >= $limit) {
            throw new QuotaExceeded($scope, $key, $limit, $used);
        }
    }

    private function default(string $name): ?int
    {
        $value = $this->config->get('llm.quota.'.$name);

        return is_numeric($value) ? (int) $value : null;
    }
}
