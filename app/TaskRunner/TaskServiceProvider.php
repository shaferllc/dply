<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\Broadcasting\TaskRunnerBroadcaster;
use App\Modules\TaskRunner\Commands\TaskListCommand;
use App\Modules\TaskRunner\Commands\TaskMakeCommand;
use App\Modules\TaskRunner\Commands\TaskRunCommand;
use App\Modules\TaskRunner\Commands\TaskShowCommand;
use App\Modules\TaskRunner\Components\TaskShellDefaultsComponent;
use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use App\Modules\TaskRunner\Contracts\TaskDispatcherInterface;
use App\Modules\TaskRunner\Services\BackgroundTaskTracker;
use App\Modules\TaskRunner\Services\CallbackService;
use App\Modules\TaskRunner\Services\ConditionalStreamingService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class TaskServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind StreamingLogger to the service container
        $this->app->singleton(StreamingLogger::class, function ($app) {
            return new StreamingLogger;
        });

        // Bind the StreamingLoggerInterface to the implementation
        $this->app->bind(StreamingLoggerInterface::class, StreamingLogger::class);

        // Bind ProcessRunner to the service container
        $this->app->singleton(ProcessRunner::class, function ($app) {
            return new ProcessRunner($app->make(StreamingLoggerInterface::class));
        });

        // Bind TaskDispatcher to the service container
        $this->app->singleton(TaskDispatcher::class, function ($app) {
            return new TaskDispatcher(
                $app->make(ProcessRunner::class),
                config('task-runner.default_timeout', 60)
            );
        });

        // Bind the interface to the implementation
        $this->app->bind(TaskDispatcherInterface::class, TaskDispatcher::class);

        // Bind TaskChain
        $this->app->singleton(TaskChain::class, function ($app) {
            return new TaskChain($app->make(TaskDispatcherInterface::class));
        });

        // Bind TaskRunnerBroadcaster
        $this->app->singleton(TaskRunnerBroadcaster::class, function ($app) {
            return new TaskRunnerBroadcaster;
        });

        // Bind ConditionalStreamingService
        $this->app->singleton(ConditionalStreamingService::class, function ($app) {
            return new ConditionalStreamingService($app->make(StreamingLoggerInterface::class));
        });

        // Bind BackgroundTaskTracker
        $this->app->singleton(BackgroundTaskTracker::class, function ($app) {
            return new BackgroundTaskTracker(
                $app->make(CallbackService::class),
                $app->make(StreamingLoggerInterface::class)
            );
        });

        // Bind CallbackService
        $this->app->singleton(CallbackService::class, function ($app) {
            return new CallbackService;
        });

        // Bind ConnectionManager
        $this->app->singleton(ConnectionManager::class, function ($app) {
            return new ConnectionManager;
        });

        // Register the facade
        $this->app->alias(TaskDispatcher::class, 'task-runner');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/task-runner.php', 'task-runner');
        $this->mergeConfigFrom(__DIR__.'/config/background.php', 'task-runner.background');

        $this->loadViewsFrom(__DIR__.'/resources/views', 'task-runner');
        // API routes are loaded centrally in RouteServiceProvider with versioning
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        Blade::anonymousComponentPath(__DIR__.'/resources/views/components', 'task-runner');
        Blade::component('task-shell-defaults', TaskShellDefaultsComponent::class);

        $this->publishes([
            __DIR__.'/resources/views' => resource_path('views/vendor/task-runner'),
            __DIR__.'/config/task-runner.php' => config_path('task-runner.php'),
        ], 'task-runner');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                TaskMakeCommand::class,
                TaskListCommand::class,
                TaskShowCommand::class,
                TaskRunCommand::class,
            ]);
        }

        // Validate configuration
        $this->validateConfiguration();

        // Register streaming handlers if enabled
        $this->registerStreamingHandlers();
    }

    /**
     * Validate the task-runner configuration.
     */
    protected function validateConfiguration(): void
    {
        $config = config('task-runner');

        if (! $config) {
            throw new \InvalidArgumentException('TaskRunner configuration not found. Please publish the config file.');
        }

        // Validate required configuration values
        $requiredKeys = ['temporary_directory', 'eof'];

        foreach ($requiredKeys as $key) {
            if (! isset($config[$key])) {
                throw new \InvalidArgumentException("TaskRunner configuration missing required key: {$key}");
            }
        }

        // Validate temporary directory is writable
        $tempDir = $config['temporary_directory'] ?: sys_get_temp_dir();
        if (! is_dir($tempDir) || ! is_writable($tempDir)) {
            throw new \InvalidArgumentException("TaskRunner temporary directory is not writable: {$tempDir}");
        }
    }

    /**
     * Register streaming handlers based on configuration.
     */
    protected function registerStreamingHandlers(): void
    {
        if (! config('task-runner.logging.streaming.enabled', true)) {
            return;
        }

        $streamingLogger = app(StreamingLoggerInterface::class);
        $handlers = config('task-runner.logging.streaming.handlers', []);

        // Register console handler
        if ($handlers['console'] ?? true) {
            $streamingLogger->addStreamHandler(function ($logData) {
                $this->handleConsoleStream($logData);
            });
        }

        // Register file handler
        if ($handlers['file'] ?? false) {
            $streamingLogger->addStreamHandler(function ($logData) {
                $this->handleFileStream($logData);
            }, 'file');
        }

        // Register websocket handler
        if ($handlers['websocket'] ?? false) {
            $broadcaster = app(TaskRunnerBroadcaster::class);
            $broadcaster->register($streamingLogger);
        }
    }

    /**
     * Handle console streaming output.
     */
    protected function handleConsoleStream(array $logData): void
    {
        $level = $logData['level'];
        $message = $logData['message'];
        $context = $logData['context'] ?? [];

        $output = "[{$logData['timestamp']}] {$level}: {$message}";

        if (! empty($context)) {
            $output .= ' '.json_encode($context);
        }

        // Output to console if running in CLI
        if (app()->runningInConsole()) {
            $this->outputToConsole($output, $level);
        }
    }

    /**
     * Handle file streaming output.
     */
    protected function handleFileStream(array $logData): void
    {
        $logFile = storage_path('logs/task-runner-streaming.log');
        $output = json_encode($logData).PHP_EOL;

        file_put_contents($logFile, $output, FILE_APPEND | LOCK_EX);
    }

    /**
     * Handle WebSocket streaming output.
     */
    protected function handleWebSocketStream(array $logData): void
    {
        // This would integrate with your WebSocket implementation
        // For now, we'll just log it to the regular log
        Log::info('WebSocket stream', $logData);
    }

    /**
     * Output to console with appropriate colors.
     */
    protected function outputToConsole(string $message, string $level): void
    {
        $colors = [
            'debug' => '37',   // White
            'info' => '32',    // Green
            'warning' => '33', // Yellow
            'error' => '31',   // Red
        ];

        $color = $colors[$level] ?? '37';
        echo "\033[{$color}m{$message}\033[0m".PHP_EOL;
    }
}
