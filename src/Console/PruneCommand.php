<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Console;

use Dskripchenko\Llm\Logging\Pruner;
use Illuminate\Console\Command;

final class PruneCommand extends Command
{
    protected $signature = 'llm:prune {--days= : keep bodies of this many recent days (default: llm.log.keep_bodies_days)}';

    protected $description = 'Prune prompt and response bodies of old LLM calls down to metrics';

    public function handle(Pruner $pruner): int
    {
        $days = $this->option('days');
        $count = $pruner->prune(is_numeric($days) ? (int) $days : null);
        $this->info("Pruned the bodies of {$count} LLM call(s).");

        return self::SUCCESS;
    }
}
