<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Logging;

use Dskripchenko\Llm\Models\Call;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;

/**
 * Drops prompt and response bodies older than `llm.log.keep_bodies_days`,
 * keeping the metrics (tokens, duration, status, subject).
 */
final class Pruner
{
    public function __construct(private readonly Config $config) {}

    /** @return int rows pruned */
    public function prune(?int $days = null): int
    {
        $configured = $this->config->get('llm.log.keep_bodies_days', 30);
        $days ??= is_numeric($configured) ? (int) $configured : 30;
        $before = Carbon::now()->subDays(max(0, $days));

        $total = 0;
        do {
            $ids = Call::query()->whereNull('bodies_pruned_at')->where('created_at', '<', $before)
                ->orderBy('id')->limit(1000)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            $total += Call::query()->whereIn('id', $ids)->update([
                'request' => null,
                'response' => null,
                'result' => null,
                'bodies_pruned_at' => Carbon::now(),
            ]);
        } while (count($ids) === 1000);

        return $total;
    }
}
