<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Oriceon\N8nBridge\Auth\CredentialAuthService;
use Oriceon\N8nBridge\Auth\N8nAuthMiddleware;
use Oriceon\N8nBridge\Auth\WebhookAuthService;
use Oriceon\N8nBridge\CircuitBreaker\CircuitBreakerManager;
use Oriceon\N8nBridge\Commands\Credential\CredentialAttachCommand;
use Oriceon\N8nBridge\Commands\Credential\CredentialCreateCommand;
use Oriceon\N8nBridge\Commands\Credential\CredentialListCommand;
use Oriceon\N8nBridge\Commands\Credential\CredentialRotateCommand;
use Oriceon\N8nBridge\Commands\DlqListCommand;
use Oriceon\N8nBridge\Commands\DlqRetryCommand;
use Oriceon\N8nBridge\Commands\EndpointCreateCommand;
use Oriceon\N8nBridge\Commands\EndpointListCommand;
use Oriceon\N8nBridge\Commands\EndpointRotateCommand;
use Oriceon\N8nBridge\Commands\HealthCommand;
use Oriceon\N8nBridge\Commands\MakeToolCommand;
use Oriceon\N8nBridge\Commands\Queue\QueueCancelCommand;
use Oriceon\N8nBridge\Commands\Queue\QueuePruneCommand;
use Oriceon\N8nBridge\Commands\Queue\QueueRetryCommand;
use Oriceon\N8nBridge\Commands\Queue\QueueStatusCommand;
use Oriceon\N8nBridge\Commands\Queue\QueueWorkCommand;
use Oriceon\N8nBridge\Commands\StatsCommand;
use Oriceon\N8nBridge\Commands\TestInboundCommand;
use Oriceon\N8nBridge\Commands\ToolCreateCommand;
use Oriceon\N8nBridge\Commands\ToolListCommand;
use Oriceon\N8nBridge\Commands\WorkflowAuthSetupCommand;
use Oriceon\N8nBridge\Commands\WorkflowsSyncCommand;
use Oriceon\N8nBridge\Events\N8nCircuitBreakerOpenedEvent;
use Oriceon\N8nBridge\Events\N8nDeliveryDeadEvent;
use Oriceon\N8nBridge\Inbound\N8nInboundController;
use Oriceon\N8nBridge\Jobs\AggregateN8nStatsJob;
use Oriceon\N8nBridge\Listeners\AlertOnCircuitBreakerOpenedListener;
use Oriceon\N8nBridge\Listeners\AlertOnDeliveryDeadListener;
use Oriceon\N8nBridge\Listeners\OutboundEventListener;
use Oriceon\N8nBridge\Notifications\NotificationDispatcher;
use Oriceon\N8nBridge\Outbound\N8nOutboundDispatcher;
use Oriceon\N8nBridge\Outbound\OutboundRateLimiter;
use Oriceon\N8nBridge\Queue\Http\QueueProgressController;
use Oriceon\N8nBridge\Queue\QueueManager;
use Oriceon\N8nBridge\Queue\Workers\QueueWorker;
use Oriceon\N8nBridge\Queue\Workers\WorkflowDurationUpdater;
use Oriceon\N8nBridge\Stats\StatsManager;
use Oriceon\N8nBridge\Tools\N8nToolController;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class N8nBridgeServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-n8n-bridge')
            ->hasConfigFile('n8n-bridge')
            ->hasCommands([
                WorkflowAuthSetupCommand::class,
                WorkflowsSyncCommand::class,
                EndpointCreateCommand::class,
                EndpointListCommand::class,
                EndpointRotateCommand::class,
                TestInboundCommand::class,
                StatsCommand::class,
                DlqListCommand::class,
                DlqRetryCommand::class,
                HealthCommand::class,
                MakeToolCommand::class,
                ToolCreateCommand::class,
                ToolListCommand::class,
                // Queue commands
                QueueWorkCommand::class,
                QueueStatusCommand::class,
                QueueRetryCommand::class,
                QueueCancelCommand::class,
                QueuePruneCommand::class,
                // Credential commands
                CredentialCreateCommand::class,
                CredentialListCommand::class,
                CredentialRotateCommand::class,
                CredentialAttachCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        // Core services
        $this->app->singleton(CredentialAuthService::class);
        $this->app->singleton(CircuitBreakerManager::class);
        $this->app->singleton(OutboundRateLimiter::class);
        $this->app->singleton(N8nOutboundDispatcher::class);
        $this->app->singleton(StatsManager::class);
        $this->app->singleton(NotificationDispatcher::class);

        $this->app->singleton(N8nBridgeManager::class, function ($app) {
            return new N8nBridgeManager(
                $app->make(N8nOutboundDispatcher::class),
                $app->make(StatsManager::class),
            );
        });

        // Queue system
        $this->app->singleton(QueueManager::class);
        $this->app->singleton(WorkflowDurationUpdater::class);
        $this->app->singleton(QueueWorker::class, function ($app) {
            return new QueueWorker(
                $app->make(N8nBridgeManager::class),
                $app->make(CircuitBreakerManager::class),
                $app->make(NotificationDispatcher::class),
                $app->make(WebhookAuthService::class),
                $app->make(OutboundRateLimiter::class),
            );
        });
    }

    public function packageBooted(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes(
                collect(glob(__DIR__.'/../database/migrations/*.php'))
                    ->mapWithKeys(fn ($path) => [
                        $path => database_path('migrations/'.basename($path)),
                    ])
                    ->toArray(),
                'n8n-bridge-migrations'
            );
        }

        // Register the N8n auth middleware alias
        $this->app['router']->aliasMiddleware('n8n.auth', N8nAuthMiddleware::class);

        $this->registerRoutes();
        $this->registerEventListeners();
        $this->registerSchedule();
    }

    private function registerRoutes(): void
    {
        $prefix = config('n8n-bridge.inbound.route_prefix', 'n8n/in');
        $middleware = config('n8n-bridge.inbound.middleware', ['api']);

        Route::prefix($prefix)
            ->middleware([...$middleware, 'n8n.auth'])
            ->group(function () {
                // Allow slashes in slug so /n8n/in/invoices/paid works alongside /n8n/in/invoices-paid
                Route::post('{slug}', [N8nInboundController::class, 'receive'])
                    ->where('slug', '.+')
                    ->name('n8n-bridge.inbound');
            });

        // Tools routes — n8n calls Laravel, all HTTP methods supported
        $toolPrefix = config('n8n-bridge.tools.route_prefix', 'n8n/tools');
        $toolMw = config('n8n-bridge.tools.middleware', ['api']);

        Route::prefix($toolPrefix)
            ->middleware([...$toolMw, 'n8n.auth'])
            ->group(function () {
                // Schema endpoint — fixed path, must be registered before the catch-all
                Route::get('schema', [N8nToolController::class, 'schema'])
                    ->name('n8n-bridge.tools.schema');

                // Catch-all {path} — allows slashes in tool names and/or IDs.
                // The controller resolves tool name vs. resource ID internally:
                //   GET  /n8n/tools/invoices/paid   → tool "invoices/paid" collection  (if tool exists)
                //                                   → tool "invoices"     item "paid"  (fallback)
                //   GET  /n8n/tools/invoices/paid/42 → tool "invoices/paid" item "42"
                //   PUT/PATCH/DELETE last segment is always the ID.
                Route::get('{path}', [N8nToolController::class, 'index'])
                    ->where('path', '.+')
                    ->name('n8n-bridge.tools.index');

                Route::post('{path}', [N8nToolController::class, 'store'])
                    ->where('path', '.+')
                    ->name('n8n-bridge.tools.store');

                Route::put('{path}', [N8nToolController::class, 'replace'])
                    ->where('path', '.+')
                    ->name('n8n-bridge.tools.replace');

                Route::patch('{path}', [N8nToolController::class, 'update'])
                    ->where('path', '.+')
                    ->name('n8n-bridge.tools.update');

                Route::delete('{path}', [N8nToolController::class, 'destroy'])
                    ->where('path', '.+')
                    ->name('n8n-bridge.tools.destroy');
            });

        // Queue progress routes — n8n sends checkpoint updates here
        $queueProgressPrefix = config('n8n-bridge.queue.progress_route_prefix', 'n8n/queue/progress');
        $queueProgressMw = config('n8n-bridge.queue.progress_middleware', ['api']);

        Route::prefix($queueProgressPrefix)
            ->middleware([...$queueProgressMw, 'n8n.auth'])
            ->group(function () {
                Route::post('{jobId}', [QueueProgressController::class, 'store'])
                    ->name('n8n-bridge.queue.progress.store');

                Route::get('{jobId}', [QueueProgressController::class, 'show'])
                    ->name('n8n-bridge.queue.progress.show');
            });

    }

    private function registerEventListeners(): void
    {
        // Alert listeners — wired to package events
        Event::listen(N8nDeliveryDeadEvent::class, AlertOnDeliveryDeadListener::class);
        Event::listen(N8nCircuitBreakerOpenedEvent::class, AlertOnCircuitBreakerOpenedListener::class);

        // Dynamic outbound event listener — fires for all app events
        if (config('n8n-bridge.outbound.listen_events', true)) {
            try {
                $table = config('n8n-bridge.table_prefix', 'n8n').'__event_subscriptions__lists';

                if (Schema::hasTable($table)) {
                    Event::listen('*', [OutboundEventListener::class, 'handle']);
                }
            } catch (\Throwable) {
                // DB unavailable: ide-helper, CI without DB, fresh install
            }
        }
    }

    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Daily stats aggregation
            $schedule
                ->job(new AggregateN8nStatsJob(now()->subDay()->toDateString()))
                ->dailyAt('00:05')
                ->name('n8n-bridge:aggregate-stats')
                ->withoutOverlapping();

            // Auto-prune old completed queue jobs (dead/cancelled older than prune_days)
            if (config('n8n-bridge.queue.auto_prune', true)) {
                $days = config('n8n-bridge.queue.prune_days', 30);
                $schedule
                    ->command("n8n:queue:prune --days={$days}")
                    ->dailyAt('01:00')
                    ->name('n8n-bridge:queue-prune')
                    ->withoutOverlapping();
            }

            // Prune done (successful) jobs sooner when delete_done_jobs is enabled.
            // Runs at 01:00 — after the 00:05 stats aggregation, guaranteeing
            // all queue stats are written to n8n__stats__lists before deletion.
            if (config('n8n-bridge.queue.delete_done_jobs', false)) {
                $doneDays = config('n8n-bridge.queue.done_jobs_prune_days', 1);
                $schedule
                    ->command("n8n:queue:prune --days={$doneDays} --status=done")
                    ->dailyAt('01:00')
                    ->name('n8n-bridge:queue-prune-done')
                    ->withoutOverlapping();
            }
        });
    }
}
