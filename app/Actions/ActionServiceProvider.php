<?php

namespace App\Actions;

use App\Actions\Console\ActionBenchmarkCommand;
use App\Actions\Console\ActionCircuitBreakerCommand;
use App\Actions\Console\ActionDependenciesCommand;
use App\Actions\Console\ActionErrorsCommand;
use App\Actions\Console\ActionHealthCommand;
use App\Actions\Console\ActionMetricsCommand;
use App\Actions\Console\ActionQueueCommand;
use App\Actions\Console\ActionRegistryCommand;
use App\Actions\Console\ActionRetryCommand;
use App\Actions\Console\GenerateActionDocsCommand;
use App\Actions\Console\GenerateApiClientCommand;
use App\Actions\Console\MakeActionCommand;
use App\Actions\DesignPatterns\ABTestableDesignPattern;
use App\Actions\DesignPatterns\ActionRateLimiterDesignPattern;
use App\Actions\DesignPatterns\ApiResponseDesignPattern;
use App\Actions\DesignPatterns\ApiVersionDesignPattern;
use App\Actions\DesignPatterns\AuditableDesignPattern;
use App\Actions\DesignPatterns\AuthenticatedDesignPattern;
use App\Actions\DesignPatterns\AuthorizedDesignPattern;
use App\Actions\DesignPatterns\BatchDesignPattern;
use App\Actions\DesignPatterns\BroadcastDesignPattern;
use App\Actions\DesignPatterns\BulkDesignPattern;
use App\Actions\DesignPatterns\CachedResultDesignPattern;
use App\Actions\DesignPatterns\CircuitBreakerDesignPattern;
use App\Actions\DesignPatterns\CommandDesignPattern;
use App\Actions\DesignPatterns\CompensationDesignPattern;
use App\Actions\DesignPatterns\ConditionalDesignPattern;
use App\Actions\DesignPatterns\ControllerDesignPattern;
use App\Actions\DesignPatterns\CostTrackingDesignPattern;
use App\Actions\DesignPatterns\EventDesignPattern;
use App\Actions\DesignPatterns\FeatureFlaggedDesignPattern;
use App\Actions\DesignPatterns\FilteredDesignPattern;
use App\Actions\DesignPatterns\IdempotentDesignPattern;
use App\Actions\DesignPatterns\JWTDesignPattern;
use App\Actions\DesignPatterns\LazyDesignPattern;
use App\Actions\DesignPatterns\LifecycleDesignPattern;
use App\Actions\DesignPatterns\ListenerDesignPattern;
use App\Actions\DesignPatterns\LockDesignPattern;
use App\Actions\DesignPatterns\LoggerDesignPattern;
use App\Actions\DesignPatterns\PasswordConfirmationDesignPattern;
use App\Actions\DesignPatterns\PermissionDesignPattern;
use App\Actions\DesignPatterns\PipelineDesignPattern;
use App\Actions\DesignPatterns\ProgressiveDesignPattern;
use App\Actions\DesignPatterns\RateLimiterDesignPattern;
use App\Actions\DesignPatterns\RequiresPlanDesignPattern;
use App\Actions\DesignPatterns\RequiresSubscriptionDesignPattern;
use App\Actions\DesignPatterns\ResourceDesignPattern;
use App\Actions\DesignPatterns\RetryDesignPattern;
use App\Actions\DesignPatterns\ReversibleDesignPattern;
use App\Actions\DesignPatterns\RuleDesignPattern;
use App\Actions\DesignPatterns\ScheduleDesignPattern;
use App\Actions\DesignPatterns\SortedDesignPattern;
use App\Actions\DesignPatterns\TestableDesignPattern;
use App\Actions\DesignPatterns\ThrottleDesignPattern;
use App\Actions\DesignPatterns\TimeoutDesignPattern;
use App\Actions\DesignPatterns\TracerDesignPattern;
use App\Actions\DesignPatterns\TransactionDesignPattern;
use App\Actions\DesignPatterns\TransformerDesignPattern;
use App\Actions\DesignPatterns\UpdateDesignPattern;
use App\Actions\DesignPatterns\ValidationDesignPattern;
use App\Actions\DesignPatterns\VersionDesignPattern;
use App\Actions\DesignPatterns\WatermarkDesignPattern;
use App\Actions\DesignPatterns\WebhookDesignPattern;
use App\Actions\Macros\ActionRouteMacros;
use App\Actions\Tracers\ActionTracer;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;

class ActionServiceProvider extends ServiceProvider
{
    protected ?ActionManager $actionManager = null;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register ActionTracer as singleton
        $this->app->singleton(ActionTracer::class);

        $designPatterns = [
            new ABTestableDesignPattern,
            new ActionRateLimiterDesignPattern,
            new ApiResponseDesignPattern,
            new ApiVersionDesignPattern,
            new AuthenticatedDesignPattern,
            new AuditableDesignPattern,
            new AuthorizedDesignPattern,
            new BatchDesignPattern,
            new BroadcastDesignPattern,
            new BulkDesignPattern,
            new CachedResultDesignPattern,
            new CircuitBreakerDesignPattern,
            new CompensationDesignPattern,
            new ConditionalDesignPattern,
            new ControllerDesignPattern,
            new CostTrackingDesignPattern,
            new EventDesignPattern,
            new FeatureFlaggedDesignPattern,
            new FilteredDesignPattern,
            new IdempotentDesignPattern,
            new JWTDesignPattern,
            new LazyDesignPattern,
            new LifecycleDesignPattern,
            new ListenerDesignPattern,
            new LockDesignPattern,
            new LoggerDesignPattern,
            new CommandDesignPattern,
            new PipelineDesignPattern,
            new EventDesignPattern,
            new ScheduleDesignPattern,
            new RuleDesignPattern,
            new PasswordConfirmationDesignPattern,
            new PermissionDesignPattern,
            new ProgressiveDesignPattern,
            new RequiresPlanDesignPattern,
            new RequiresSubscriptionDesignPattern,
            new ResourceDesignPattern,
            new ActionRateLimiterDesignPattern,
            new RateLimiterDesignPattern,
            new ReversibleDesignPattern,
            new RetryDesignPattern,
            new SortedDesignPattern,
            new TransformerDesignPattern,
            new TransactionDesignPattern,
            new TracerDesignPattern,
            new TestableDesignPattern,
            new ThrottleDesignPattern,
            new TimeoutDesignPattern,
            new UpdateDesignPattern,
            new ValidationDesignPattern,
            new VersionDesignPattern,
            new WebhookDesignPattern,
            new WatermarkDesignPattern,
        ];

        $this->app->scoped(ActionManager::class, function () use ($designPatterns) {
            return new ActionManager($designPatterns);
        });

        // Create ActionManager instance directly to avoid container resolution loops
        $this->actionManager = new ActionManager($designPatterns);

        $this->extendActions();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish Stubs File
            $this->publishes([
                __DIR__.'/Console/stubs/action.stub' => base_path('stubs/action.stub'),
            ], 'stubs');

            // Register the make:action generator command.
            $this->commands([
                MakeActionCommand::class,
                GenerateActionDocsCommand::class,
                ActionDependenciesCommand::class,
                ActionMetricsCommand::class,
                ActionHealthCommand::class,
                ActionRegistryCommand::class,
                GenerateApiClientCommand::class,
                ActionCircuitBreakerCommand::class,
                ActionRetryCommand::class,
                ActionQueueCommand::class,
                ActionErrorsCommand::class,
                ActionBenchmarkCommand::class,
            ]);
        }

        Route::mixin(new ActionRouteMacros);

        // Listener auto-discovery was removed along with ListenerAutoDiscovery,
        // which scanned via lorisleiva/lody — a package that was never installed,
        // so every scan threw and was swallowed by the catch below it. Register
        // action listeners explicitly in an EventServiceProvider instead.
    }

    protected function extendActions(): void
    {
        $actionManager = $this->actionManager;

        $this->app->beforeResolving(function ($abstract, $parameters, Application $app) use ($actionManager) {
            if ($abstract === ActionManager::class) {
                return;
            }

            try {
                // Fix conflict with package: barryvdh/laravel-ide-helper.
                // @see https://github.com/lorisleiva/laravel-actions/issues/142
                $classExists = class_exists($abstract);
            } catch (\ReflectionException) {
                return;
            }

            if (! $classExists || $app->resolved($abstract)) {
                return;
            }

            // Use the cached ActionManager instance to avoid container resolution loops
            $actionManager->extend($app, $abstract);
        });
    }
}
