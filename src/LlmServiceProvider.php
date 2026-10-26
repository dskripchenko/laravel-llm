<?php

declare(strict_types=1);

namespace Dskripchenko\Llm;

use Dskripchenko\Llm\Console\PruneCommand;
use Dskripchenko\Llm\Prompts\Prompt;
use Dskripchenko\Llm\Prompts\PromptRepository;
use Dskripchenko\Llm\Quota\QuotaGuard;
use Dskripchenko\Llm\Routing\Router;
use Dskripchenko\Llm\Transport\OpenAiCompatibleTransport;
use Dskripchenko\Llm\Transport\Transport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/llm.php', 'llm');

        $this->app->bind(Transport::class, OpenAiCompatibleTransport::class);
        $this->app->singleton(Router::class);
        $this->app->singleton(QuotaGuard::class);
        $this->app->singleton(Executor::class);
        $this->app->singleton(LlmManager::class);
        $this->app->singleton(PromptRepository::class, function (Application $app): PromptRepository {
            $repository = new PromptRepository($app);
            $presets = $app->make(Config::class)->get('llm.prompts.presets', []);
            foreach (is_array($presets) ? $presets : [] as $preset) {
                if (is_string($preset) && is_subclass_of($preset, Prompt::class)) {
                    $repository->register($preset);
                }
            }

            return $repository;
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/llm.php' => $this->app->configPath('llm.php')], 'llm-config');
            $this->publishes([__DIR__.'/../database/migrations' => $this->app->databasePath('migrations')], 'llm-migrations');
            $this->commands([PruneCommand::class]);
        }

        if ($this->app->make(Config::class)->get('llm.log.schedule_prune', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('llm:prune')->daily();
            });
        }
    }
}
