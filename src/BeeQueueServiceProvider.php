<?php

namespace G4T\BeeQueue;

use G4T\BeeQueue\Console\StatsCommand;
use G4T\BeeQueue\Console\WorkCommand;
use G4T\BeeQueue\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
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
        // Views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'bee-queue');

        // Dashboard routes
        $this->registerRoutes();

        // Always publish assets (needed for dashboard logo)
        $this->publishes([
            __DIR__ . '/../images' => public_path('vendor/bee-queue'),
        ], 'bee-queue-assets');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/bee-queue.php' => config_path('bee-queue.php'),
            ], 'bee-queue-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/bee-queue'),
            ], 'bee-queue-views');

            $this->commands([
                WorkCommand::class,
                StatsCommand::class,
            ]);
        }
    }

    protected function registerRoutes(): void
    {
        $config = $this->app['config']['bee-queue.dashboard'] ?? [];
        $prefix = $config['path'] ?? 'bee-queue';
        $middleware = $config['middleware'] ?? ['web'];

        Route::middleware($middleware)
            ->prefix($prefix)
            ->name('bee-queue.')
            ->group(function () {
                Route::get('/',                              [DashboardController::class, 'index'])->name('dashboard');
                Route::post('/{queue}/{job}/retry',          [DashboardController::class, 'retry'])->name('retry');
                Route::delete('/{queue}/{job}',              [DashboardController::class, 'delete'])->name('delete');
            });
    }
}
