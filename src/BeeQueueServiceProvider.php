<?php

namespace G4T\BeeQueue;

use G4T\BeeQueue\Console\StatsCommand;
use G4T\BeeQueue\Console\WorkCommand;
use Illuminate\Support\ServiceProvider;

class BeeQueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/bee-queue.php', 'bee-queue');

        $this->app->singleton('bee-queue', function ($app) {
            return new QueueManager($app['config']['bee-queue']);
        });

        $this->app->alias('bee-queue', QueueManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/bee-queue.php' => config_path('bee-queue.php'),
            ], 'bee-queue-config');

            $this->commands([
                WorkCommand::class,
                StatsCommand::class,
            ]);
        }
    }
}
