<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Examples;

use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\Services\ConditionalStreamingService;
use App\Modules\TaskRunner\Task;
use App\Modules\TaskRunner\TaskChain;
use App\Modules\TaskRunner\Traits\HasProgressTracking;
use App\Modules\TaskRunner\View\TaskViewRenderer;

/**
 * Example demonstrating complex view usage with all streaming features.
 */
class ComplexViewExample
{
    /**
     * Example of using complex database backup view with streaming.
     */
    public static function runDatabaseBackupWithComplexView(): void
    {
        // Create a complex database backup task
        $backupTask = DatabaseBackupTask::forDatabase('myapp_production', '/backups/daily')
            ->withDatabaseConnection('localhost', 'backup_user', 'secure_password', '3306')
            ->withDumpOptions(['--single-transaction', '--routines', '--triggers'])
            ->withS3Upload('my-backup-bucket', 'daily-backups')
            ->withSlackNotifications('https://hooks.slack.com/services/YOUR/WEBHOOK/URL')
            ->withDumpOptions(['--compress']);

        // Configure conditional streaming
        $conditionalService = app(ConditionalStreamingService::class);
        $conditionalService->configureTaskStreaming($backupTask, [
            'priority' => ConditionalStreamingService::PRIORITY_HIGH,
            'category' => ConditionalStreamingService::CATEGORY_BACKUP,
            'notify_on_completion' => true,
            'notify_on_error' => true,
        ]);

        // Run the task with streaming
        $result = TaskRunner::run($backupTask);

        echo 'Backup completed with exit code: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using complex deployment view with streaming.
     */
    public static function runDeploymentWithComplexView(): void
    {
        // Create a complex Laravel deployment task
        $deploymentTask = DeploymentTask::laravel(
            'MyAwesomeApp',
            '/var/www/myapp',
            'https://github.com/mycompany/myapp.git'
        )
            ->onBranch('production')
            ->withHealthCheck('https://myapp.com/health')
            ->withSlackNotifications('https://hooks.slack.com/services/YOUR/WEBHOOK/URL')
            ->withTesting('php artisan test --parallel');

        // Configure conditional streaming
        $conditionalService = app(ConditionalStreamingService::class);
        $conditionalService->configureTaskStreaming($deploymentTask, [
            'priority' => ConditionalStreamingService::PRIORITY_CRITICAL,
            'category' => ConditionalStreamingService::CATEGORY_DEPLOYMENT,
            'notify_on_completion' => true,
            'notify_on_error' => true,
        ]);

        // Run the task with streaming
        $result = TaskRunner::run($deploymentTask);

        echo 'Deployment completed with exit code: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using complex system maintenance view.
     */
    public static function runSystemMaintenanceWithComplexView(): void
    {
        // Create a system maintenance task using the complex view
        $maintenanceTask = new class extends Task
        {
            use HasProgressTracking;

            public string $backup_dir = '/backups';

            public int $cleanup_older_than_days = 30;

            public int $max_log_size_mb = 100;

            public bool $notify_on_completion = true;

            public array $operations = [
                'disk_cleanup',
                'log_rotation',
                'package_updates',
                'security_updates',
                'backup_verification',
                'system_health_check',
            ];

            public bool $cleanup_package_cache = true;

            public string $package_manager = 'apt';

            public bool $cleanup_docker = true;

            public bool $rotate_system_logs = true;

            public bool $update_packages = true;

            public bool $security_updates = true;

            public bool $verify_backup_integrity = true;

            public int $disk_usage_threshold = 80;

            public array $critical_services = ['nginx', 'mysql', 'redis'];

            public bool $database_maintenance = true;

            public string $db_type = 'mysql';

            public string $db_user = 'maintenance_user';

            public string $db_password = 'secure_password';

            public string $db_name = 'myapp';

            public array $restart_services = ['nginx'];

            public string $notification_method = 'slack';

            public string $slack_webhook_url = 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL';

            public function getView(): string
            {
                return 'tasks.system-maintenance';
            }

            public function getViewData(): array
            {
                return [
                    'backup_dir' => $this->backup_dir,
                    'cleanup_older_than_days' => $this->cleanup_older_than_days,
                    'max_log_size_mb' => $this->max_log_size_mb,
                    'notify_on_completion' => $this->notify_on_completion ? 'true' : 'false',
                    'operations' => $this->operations,
                    'cleanup_package_cache' => $this->cleanup_package_cache,
                    'package_manager' => $this->package_manager,
                    'cleanup_docker' => $this->cleanup_docker,
                    'rotate_system_logs' => $this->rotate_system_logs,
                    'update_packages' => $this->update_packages,
                    'security_updates' => $this->security_updates,
                    'verify_backup_integrity' => $this->verify_backup_integrity,
                    'disk_usage_threshold' => $this->disk_usage_threshold,
                    'critical_services' => $this->critical_services,
                    'database_maintenance' => $this->database_maintenance,
                    'db_type' => $this->db_type,
                    'db_user' => $this->db_user,
                    'db_password' => $this->db_password,
                    'db_name' => $this->db_name,
                    'restart_services' => $this->restart_services,
                    'notification_method' => $this->notification_method,
                    'slack_webhook_url' => $this->slack_webhook_url,
                ];
            }
        };

        // Initialize progress tracking
        $maintenanceTask->initializeProgress(6, [
            'Step 1: Disk cleanup',
            'Step 2: Log rotation',
            'Step 3: Package updates',
            'Step 4: Security updates',
            'Step 5: Backup verification',
            'Step 6: System health check',
        ]);

        // Configure conditional streaming
        $conditionalService = app(ConditionalStreamingService::class);
        $conditionalService->configureTaskStreaming($maintenanceTask, [
            'priority' => ConditionalStreamingService::PRIORITY_NORMAL,
            'category' => ConditionalStreamingService::CATEGORY_MAINTENANCE,
            'notify_on_completion' => true,
            'notify_on_error' => true,
        ]);

        // Run the task with streaming
        $result = TaskRunner::run($maintenanceTask);

        echo 'Maintenance completed with exit code: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using task chains with complex views.
     */
    public static function runTaskChainWithComplexViews(): array
    {
        // Create a chain of complex tasks
        $chain = TaskChain::make()
            ->withStreaming(true)
            ->stopOnFailure(false);

        // Add database backup task
        $backupTask = DatabaseBackupTask::forDatabase('myapp_production', '/backups')
            ->withS3Upload('my-backup-bucket', 'backups')
            ->withSlackNotifications('https://hooks.slack.com/services/YOUR/WEBHOOK/URL');

        // Add deployment task
        $deploymentTask = DeploymentTask::laravel(
            'MyAwesomeApp',
            '/var/www/myapp',
            'https://github.com/mycompany/myapp.git'
        )
            ->withHealthCheck('https://myapp.com/health')
            ->withSlackNotifications('https://hooks.slack.com/services/YOUR/WEBHOOK/URL');

        // Add maintenance task
        $maintenanceTask = new class extends Task
        {
            public string $backup_dir = '/backups';

            public array $operations = ['disk_cleanup', 'log_rotation'];

            public string $notification_method = 'slack';

            public string $slack_webhook_url = 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL';

            public function getView(): string
            {
                return 'tasks.system-maintenance';
            }

            public function getViewData(): array
            {
                return [
                    'backup_dir' => $this->backup_dir,
                    'operations' => $this->operations,
                    'notification_method' => $this->notification_method,
                    'slack_webhook_url' => $this->slack_webhook_url,
                ];
            }
        };

        // Add tasks to chain
        $chain->addMany([$backupTask, $deploymentTask, $maintenanceTask]);

        // Run the chain
        $results = $chain->run();

        echo 'Task chain completed. Successful: '.($results['successful'] ? 'Yes' : 'No')."\n";
        echo 'Total tasks: '.$results['total_tasks']."\n";
        echo 'Successful tasks: '.$results['successful_tasks']."\n";
        echo 'Failed tasks: '.count($results['failed_tasks'])."\n";

        return $results;
    }

    /**
     * Example of using view caching and validation.
     */
    public static function demonstrateViewFeatures(): void
    {
        // Get view renderer for a task
        $task = DatabaseBackupTask::forDatabase('test_db', '/tmp/backups');
        $renderer = new TaskViewRenderer($task);

        // Validate the view
        try {
            $renderer->validateView();
            echo "View validation passed\n";
        } catch (\Exception $e) {
            echo 'View validation failed: '.$e->getMessage()."\n";
        }

        // Get view statistics
        $stats = $renderer->getStats();
        echo 'View stats: '.json_encode($stats, JSON_PRETTY_PRINT)."\n";

        // Get available views
        $availableViews = TaskViewRenderer::getAvailableViews();
        echo 'Available views: '.implode(', ', $availableViews)."\n";

        // Precompile all views
        $precompileResults = TaskViewRenderer::precompileViews();
        echo 'Precompile results: '.json_encode($precompileResults, JSON_PRETTY_PRINT)."\n";
    }

    /**
     * Example of using custom view composers.
     */
    public static function demonstrateViewComposers(): void
    {
        // Configure view composers in config
        config([
            'task-runner.view.composers' => [
                'tasks.database-backup' => function ($view, $task) {
                    $view->with('custom_data', 'This is custom data from composer');
                    $view->with('timestamp', now()->toISOString());
                },
            ],
        ]);

        // Create a task that will use the composer
        $task = DatabaseBackupTask::forDatabase('test_db', '/tmp/backups');

        // The view will automatically receive the custom data from the composer
        $result = TaskRunner::run($task);

        echo 'Task with composer completed with exit code: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using helper functions in views.
     */
    public static function demonstrateViewHelpers(): void
    {
        // Create a task that uses helper functions
        $task = new class extends Task
        {
            public string $file_path = '/path/with spaces/file.txt';

            public array $command_args = ['ls', '-la', '/var/log'];

            public string $config_key = 'app.debug';

            public function getView(): string
            {
                return 'tasks.helper-example';
            }

            public function getViewData(): array
            {
                return [
                    'file_path' => $this->file_path,
                    'command_args' => $this->command_args,
                    'config_key' => $this->config_key,
                ];
            }
        };

        // The view can use helper functions like:
        // {{ $file_path|escape_shell_arg }}
        // {{ $command_args|join_args }}
        // {{ $config_key|config }}

        $result = TaskRunner::run($task);

        echo 'Task with helpers completed with exit code: '.$result->getExitCode()."\n";
    }

    /**
     * Run all complex view examples.
     */
    public static function runAllExamples(): void
    {
        echo "=== Running Complex View Examples ===\n\n";

        echo "1. Database Backup with Complex View:\n";
        self::runDatabaseBackupWithComplexView();
        echo "\n";

        echo "2. Deployment with Complex View:\n";
        self::runDeploymentWithComplexView();
        echo "\n";

        echo "3. System Maintenance with Complex View:\n";
        self::runSystemMaintenanceWithComplexView();
        echo "\n";

        echo "4. Task Chain with Complex Views:\n";
        self::runTaskChainWithComplexViews();
        echo "\n";

        echo "5. View Features Demonstration:\n";
        self::demonstrateViewFeatures();
        echo "\n";

        echo "6. View Composers Demonstration:\n";
        self::demonstrateViewComposers();
        echo "\n";

        echo "7. View Helpers Demonstration:\n";
        self::demonstrateViewHelpers();
        echo "\n";

        echo "=== All Examples Completed ===\n";
    }
}
