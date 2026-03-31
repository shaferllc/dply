<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Examples;

use App\Modules\TaskRunner\Task;
use App\Modules\TaskRunner\Traits\HasProgressTracking;

class DeploymentTask extends Task
{
    use HasProgressTracking;

    public string $deploy_path;

    public string $repo_url;

    public string $branch = 'main';

    public string $backup_path = '/tmp/deployments';

    public int $max_deployments = 5;

    public bool $notify_on_success = true;

    public bool $notify_on_error = true;

    public bool $rollback_on_failure = true;

    public string $app_name;

    public ?string $package_manager = null;

    public bool $build_assets = false;

    public ?string $build_command = null;

    public bool $run_migrations = false;

    public ?string $migration_command = null;

    public bool $clear_caches = true;

    public array $cache_commands = [];

    public bool $run_tests = false;

    public ?string $test_command = null;

    public ?string $health_check_url = null;

    public ?string $notification_method = null;

    public ?string $slack_webhook_url = null;

    public ?string $email_address = null;

    public function __construct(array $attributes = [])
    {
        parent::__construct();

        foreach ($attributes as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function getView(): string
    {
        return 'tasks.deployment';
    }

    public function getViewData(): array
    {
        return [
            'deploy_path' => $this->deploy_path,
            'repo_url' => $this->repo_url,
            'branch' => $this->branch,
            'backup_path' => $this->backup_path,
            'max_deployments' => $this->max_deployments,
            'notify_on_success' => $this->notify_on_success ? 'true' : 'false',
            'notify_on_error' => $this->notify_on_error ? 'true' : 'false',
            'rollback_on_failure' => $this->rollback_on_failure ? 'true' : 'false',
            'app_name' => $this->app_name,
            'package_manager' => $this->package_manager,
            'build_assets' => $this->build_assets,
            'build_command' => $this->build_command,
            'run_migrations' => $this->run_migrations,
            'migration_command' => $this->migration_command,
            'clear_caches' => $this->clear_caches,
            'cache_commands' => $this->cache_commands,
            'run_tests' => $this->run_tests,
            'test_command' => $this->test_command,
            'health_check_url' => $this->health_check_url,
            'notification_method' => $this->notification_method,
            'slack_webhook_url' => $this->slack_webhook_url,
            'email_address' => $this->email_address,
        ];
    }

    public function validate(): void
    {
        parent::validate();

        if (empty($this->deploy_path)) {
            throw new \InvalidArgumentException('Deploy path is required');
        }

        if (empty($this->repo_url)) {
            throw new \InvalidArgumentException('Repository URL is required');
        }

        if (empty($this->app_name)) {
            throw new \InvalidArgumentException('App name is required');
        }

        if ($this->package_manager && ! in_array($this->package_manager, ['composer', 'npm', 'yarn'])) {
            throw new \InvalidArgumentException('Package manager must be composer, npm, or yarn');
        }

        if ($this->notification_method && ! in_array($this->notification_method, ['slack', 'email'])) {
            throw new \InvalidArgumentException('Notification method must be slack or email');
        }

        if ($this->notification_method === 'slack' && empty($this->slack_webhook_url)) {
            throw new \InvalidArgumentException('Slack webhook URL is required for Slack notifications');
        }

        if ($this->notification_method === 'email' && empty($this->email_address)) {
            throw new \InvalidArgumentException('Email address is required for email notifications');
        }
    }

    /**
     * Configure package manager.
     */
    public function withPackageManager(string $manager): self
    {
        $this->package_manager = $manager;

        return $this;
    }

    /**
     * Enable asset building.
     */
    public function withAssetBuilding(?string $command = null): self
    {
        $this->build_assets = true;
        $this->build_command = $command;

        return $this;
    }

    /**
     * Enable database migrations.
     */
    public function withMigrations(?string $command = null): self
    {
        $this->run_migrations = true;
        $this->migration_command = $command;

        return $this;
    }

    /**
     * Configure cache clearing.
     */
    public function withCacheClearing(array $commands = []): self
    {
        $this->clear_caches = true;
        $this->cache_commands = $commands;

        return $this;
    }

    /**
     * Enable testing.
     */
    public function withTesting(?string $command = null): self
    {
        $this->run_tests = true;
        $this->test_command = $command;

        return $this;
    }

    /**
     * Configure health check.
     */
    public function withHealthCheck(string $url): self
    {
        $this->health_check_url = $url;

        return $this;
    }

    /**
     * Configure Slack notifications.
     */
    public function withSlackNotifications(string $webhook_url): self
    {
        $this->notification_method = 'slack';
        $this->slack_webhook_url = $webhook_url;

        return $this;
    }

    /**
     * Configure email notifications.
     */
    public function withEmailNotifications(string $email_address): self
    {
        $this->notification_method = 'email';
        $this->email_address = $email_address;

        return $this;
    }

    /**
     * Disable rollback on failure.
     */
    public function withoutRollback(): self
    {
        $this->rollback_on_failure = false;

        return $this;
    }

    /**
     * Set deployment branch.
     */
    public function onBranch(string $branch): self
    {
        $this->branch = $branch;

        return $this;
    }

    /**
     * Create a deployment task for a specific application.
     */
    public static function forApp(string $app_name, string $deploy_path, string $repo_url): self
    {
        return new self([
            'app_name' => $app_name,
            'deploy_path' => $deploy_path,
            'repo_url' => $repo_url,
        ]);
    }

    /**
     * Create a Laravel deployment task.
     */
    public static function laravel(string $app_name, string $deploy_path, string $repo_url): self
    {
        return (new self([
            'app_name' => $app_name,
            'deploy_path' => $deploy_path,
            'repo_url' => $repo_url,
        ]))
            ->withPackageManager('composer')
            ->withMigrations()
            ->withCacheClearing([
                'php artisan cache:clear',
                'php artisan config:clear',
                'php artisan route:clear',
                'php artisan view:clear',
            ])
            ->withTesting();
    }

    /**
     * Create a Node.js deployment task.
     */
    public static function nodejs(string $app_name, string $deploy_path, string $repo_url, string $package_manager = 'npm'): self
    {
        return (new self([
            'app_name' => $app_name,
            'deploy_path' => $deploy_path,
            'repo_url' => $repo_url,
        ]))
            ->withPackageManager($package_manager)
            ->withAssetBuilding();
    }

    /**
     * Create a deployment task with common configurations.
     */
    public static function create(string $app_name, string $deploy_path, string $repo_url, array $options = []): self
    {
        $task = new self([
            'app_name' => $app_name,
            'deploy_path' => $deploy_path,
            'repo_url' => $repo_url,
        ]);

        // Apply options
        foreach ($options as $key => $value) {
            if (property_exists($task, $key)) {
                $task->$key = $value;
            }
        }

        return $task;
    }
}
