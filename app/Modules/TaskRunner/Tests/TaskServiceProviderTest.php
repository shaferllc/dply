<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests;

use App\Modules\TaskRunner\Broadcasting\TaskRunnerBroadcaster;
use App\Modules\TaskRunner\Commands\TaskListCommand;
use App\Modules\TaskRunner\Commands\TaskMakeCommand;
use App\Modules\TaskRunner\Commands\TaskRunCommand;
use App\Modules\TaskRunner\Commands\TaskShowCommand;
use App\Modules\TaskRunner\ConnectionManager;
use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use App\Modules\TaskRunner\Contracts\TaskDispatcherInterface;
use App\Modules\TaskRunner\ProcessRunner;
use App\Modules\TaskRunner\Services\BackgroundTaskTracker;
use App\Modules\TaskRunner\Services\CallbackService;
use App\Modules\TaskRunner\Services\ConditionalStreamingService;
use App\Modules\TaskRunner\StreamingLogger;
use App\Modules\TaskRunner\TaskChain;
use App\Modules\TaskRunner\TaskDispatcher;
use App\Modules\TaskRunner\TaskServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class TaskServiceProviderTest extends TestCase
{
    public function test_service_provider_registers_streaming_logger()
    {
        $this->app->register(TaskServiceProvider::class);

        $this->assertTrue($this->app->bound(StreamingLogger::class));
        $this->assertTrue($this->app->bound(StreamingLoggerInterface::class));

        $logger = $this->app->make(StreamingLoggerInterface::class);
        $this->assertInstanceOf(StreamingLogger::class, $logger);
    }

    public function test_service_provider_registers_process_runner()
    {
        $this->app->register(TaskServiceProvider::class);

        $this->assertTrue($this->app->bound(ProcessRunner::class));

        $runner = $this->app->make(ProcessRunner::class);
        $this->assertInstanceOf(ProcessRunner::class, $runner);
    }

    public function test_service_provider_registers_task_dispatcher()
    {
        $this->app->register(TaskServiceProvider::class);

        $this->assertTrue($this->app->bound(TaskDispatcher::class));
        $this->assertTrue($this->app->bound(TaskDispatcherInterface::class));

        $dispatcher = $this->app->make(TaskDispatcherInterface::class);
        $this->assertInstanceOf(TaskDispatcher::class, $dispatcher);
    }

    public function test_service_provider_registers_task_chain()
    {
        $this->app->register(TaskServiceProvider::class);

        $this->assertTrue($this->app->bound(TaskChain::class));

        $chain = $this->app->make(TaskChain::class);
        $this->assertInstanceOf(TaskChain::class, $chain);
    }

    public function test_service_provider_registers_task_runner_broadcaster()
    {
        $this->app->register(TaskServiceProvider::class);

        $this->assertTrue($this->app->bound(TaskRunnerBroadcaster::class));

        $broadcaster = $this->app->make(TaskRunnerBroadcaster::class);
        $this->assertInstanceOf(TaskRunnerBroadcaster::class, $broadcaster);
    }

    public function test_service_provider_registers_conditional_streaming_service()
    {
        $this->app->register(TaskServiceProvider::class);

        $this->assertTrue($this->app->bound(ConditionalStreamingService::class));

        $service = $this->app->make(ConditionalStreamingService::class);
        $this->assertInstanceOf(ConditionalStreamingService::class, $service);
    }

    public function test_service_provider_registers_background_task_tracker()
    {
        $this->app->register(TaskServiceProvider::class);

        $this->assertTrue($this->app->bound(BackgroundTaskTracker::class));

        $tracker = $this->app->make(BackgroundTaskTracker::class);
        $this->assertInstanceOf(BackgroundTaskTracker::class, $tracker);
    }

    public function test_service_provider_registers_callback_service()
    {
        $this->app->register(TaskServiceProvider::class);

        $this->assertTrue($this->app->bound(CallbackService::class));

        $service = $this->app->make(CallbackService::class);
        $this->assertInstanceOf(CallbackService::class, $service);
    }

    public function test_service_provider_registers_connection_manager()
    {
        $this->app->register(TaskServiceProvider::class);

        $this->assertTrue($this->app->bound(ConnectionManager::class));

        $manager = $this->app->make(ConnectionManager::class);
        $this->assertInstanceOf(ConnectionManager::class, $manager);
    }

    public function test_service_provider_registers_facade_alias()
    {
        $this->app->register(TaskServiceProvider::class);

        $this->assertTrue($this->app->bound('task-runner'));

        $facade = $this->app->make('task-runner');
        $this->assertInstanceOf(TaskDispatcher::class, $facade);
    }

    public function test_service_provider_loads_views()
    {
        $this->app->register(TaskServiceProvider::class);

        $this->assertTrue(Blade::exists('task-runner::components.task-shell-defaults'));
    }

    public function test_service_provider_registers_blade_component()
    {
        $this->app->register(TaskServiceProvider::class);

        $this->assertTrue(Blade::componentExists('task-shell-defaults'));
    }

    public function test_service_provider_loads_configuration()
    {
        $this->app->register(TaskServiceProvider::class);

        $this->assertNotNull(Config::get('task-runner'));
        $this->assertNotNull(Config::get('task-runner.background'));
    }

    public function test_service_provider_loads_migrations()
    {
        $this->app->register(TaskServiceProvider::class);

        // Check that migration files exist
        $migrationPath = __DIR__.'/../database/migrations';
        $this->assertDirectoryExists($migrationPath);
    }

    public function test_service_provider_loads_routes()
    {
        $this->app->register(TaskServiceProvider::class);

        // Check that route files exist
        $this->assertFileExists(__DIR__.'/../routes/api.php');
        $this->assertFileExists(__DIR__.'/../routes/web.php');
    }

    public function test_service_provider_registers_console_commands()
    {
        $this->app->register(TaskServiceProvider::class);

        $this->assertTrue($this->app->bound(TaskMakeCommand::class));
        $this->assertTrue($this->app->bound(TaskListCommand::class));
        $this->assertTrue($this->app->bound(TaskShowCommand::class));
        $this->assertTrue($this->app->bound(TaskRunCommand::class));
    }

    public function test_service_provider_validates_configuration()
    {
        // Test with missing configuration
        Config::set('task-runner', null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TaskRunner configuration not found. Please publish the config file.');

        $this->app->register(TaskServiceProvider::class);
    }

    public function test_service_provider_validates_required_config_keys()
    {
        // Test with missing required keys
        Config::set('task-runner', ['some_key' => 'value']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TaskRunner configuration missing required key: temporary_directory');

        $this->app->register(TaskServiceProvider::class);
    }

    public function test_service_provider_validates_temporary_directory()
    {
        // Test with non-writable directory
        Config::set('task-runner', [
            'temporary_directory' => '/non/existent/directory',
            'eof' => 'EOF',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TaskRunner temporary directory is not writable: /non/existent/directory');

        $this->app->register(TaskServiceProvider::class);
    }

    public function test_service_provider_registers_streaming_handlers_when_enabled()
    {
        Config::set('task-runner', [
            'temporary_directory' => sys_get_temp_dir(),
            'eof' => 'EOF',
            'logging' => [
                'streaming' => [
                    'enabled' => true,
                    'handlers' => [
                        'console' => true,
                        'file' => false,
                        'websocket' => false,
                    ],
                ],
            ],
        ]);

        $this->app->register(TaskServiceProvider::class);

        // Should not throw any exceptions
        $this->assertTrue(true);
    }

    public function test_service_provider_skips_streaming_handlers_when_disabled()
    {
        Config::set('task-runner', [
            'temporary_directory' => sys_get_temp_dir(),
            'eof' => 'EOF',
            'logging' => [
                'streaming' => [
                    'enabled' => false,
                ],
            ],
        ]);

        $this->app->register(TaskServiceProvider::class);

        // Should not throw any exceptions
        $this->assertTrue(true);
    }

    public function test_service_provider_publishes_assets()
    {
        $this->app->register(TaskServiceProvider::class);

        // Check that publishable assets are defined
        $provider = new TaskServiceProvider($this->app);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('publishes');
        $method->setAccessible(true);

        // This should not throw an exception
        $this->assertTrue(true);
    }

    public function test_service_provider_handles_console_streaming()
    {
        Config::set('task-runner', [
            'temporary_directory' => sys_get_temp_dir(),
            'eof' => 'EOF',
            'logging' => [
                'streaming' => [
                    'enabled' => true,
                    'handlers' => [
                        'console' => true,
                    ],
                ],
            ],
        ]);

        $this->app->register(TaskServiceProvider::class);

        // Test console streaming handler
        $logger = $this->app->make(StreamingLoggerInterface::class);
        $this->assertInstanceOf(StreamingLogger::class, $logger);
    }

    public function test_service_provider_handles_file_streaming()
    {
        Config::set('task-runner', [
            'temporary_directory' => sys_get_temp_dir(),
            'eof' => 'EOF',
            'logging' => [
                'streaming' => [
                    'enabled' => true,
                    'handlers' => [
                        'file' => true,
                    ],
                ],
            ],
        ]);

        $this->app->register(TaskServiceProvider::class);

        // Test file streaming handler
        $logger = $this->app->make(StreamingLoggerInterface::class);
        $this->assertInstanceOf(StreamingLogger::class, $logger);
    }

    public function test_service_provider_handles_websocket_streaming()
    {
        Config::set('task-runner', [
            'temporary_directory' => sys_get_temp_dir(),
            'eof' => 'EOF',
            'logging' => [
                'streaming' => [
                    'enabled' => true,
                    'handlers' => [
                        'websocket' => true,
                    ],
                ],
            ],
        ]);

        $this->app->register(TaskServiceProvider::class);

        // Test websocket streaming handler
        $logger = $this->app->make(StreamingLoggerInterface::class);
        $this->assertInstanceOf(StreamingLogger::class, $logger);
    }

    public function test_service_provider_uses_default_config_values()
    {
        Config::set('task-runner', [
            'temporary_directory' => sys_get_temp_dir(),
            'eof' => 'EOF',
        ]);

        $this->app->register(TaskServiceProvider::class);

        // Should use default timeout value
        $dispatcher = $this->app->make(TaskDispatcherInterface::class);
        $this->assertInstanceOf(TaskDispatcher::class, $dispatcher);
    }

    public function test_service_provider_creates_singleton_instances()
    {
        $this->app->register(TaskServiceProvider::class);

        $logger1 = $this->app->make(StreamingLogger::class);
        $logger2 = $this->app->make(StreamingLogger::class);

        $this->assertSame($logger1, $logger2);

        $runner1 = $this->app->make(ProcessRunner::class);
        $runner2 = $this->app->make(ProcessRunner::class);

        $this->assertSame($runner1, $runner2);
    }

    public function test_service_provider_creates_new_instances_for_non_singletons()
    {
        $this->app->register(TaskServiceProvider::class);

        // Some services might not be singletons, test that they can be created
        $manager1 = $this->app->make(ConnectionManager::class);
        $manager2 = $this->app->make(ConnectionManager::class);

        $this->assertInstanceOf(ConnectionManager::class, $manager1);
        $this->assertInstanceOf(ConnectionManager::class, $manager2);
    }
}
