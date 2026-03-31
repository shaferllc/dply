<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\View;

use App\Modules\TaskRunner\Task;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as BladeView;

class TaskViewRenderer
{
    /**
     * The task instance.
     */
    protected Task $task;

    /**
     * View compilation cache key.
     */
    protected string $cacheKey;

    /**
     * Whether to use view caching.
     */
    protected bool $useCache;

    /**
     * Cache TTL in seconds.
     */
    protected int $cacheTtl;

    /**
     * Create a new TaskViewRenderer instance.
     */
    public function __construct(Task $task)
    {
        $this->task = $task;
        $this->cacheKey = $this->generateCacheKey();
        $this->useCache = config('task-runner.view.cache.enabled', true);
        $this->cacheTtl = config('task-runner.view.cache.ttl', 3600);
    }

    /**
     * Render the task view.
     */
    public function render(): string
    {
        // Check cache first
        if ($this->useCache && $this->hasCachedView()) {
            return $this->getCachedView();
        }

        // Render the view
        $script = $this->renderView();

        // Cache the result
        if ($this->useCache) {
            $this->cacheView($script);
        }

        return $script;
    }

    /**
     * Render the view with data.
     */
    protected function renderView(): string
    {
        $viewName = $this->task->getView();
        $data = $this->prepareViewData();

        // Check if view exists
        if (! View::exists($viewName)) {
            throw new \InvalidArgumentException("View '{$viewName}' does not exist.");
        }

        // Create the view
        $view = View::make($viewName, $data);

        // Apply view composers if any
        $this->applyViewComposers($view);

        // Render the view
        return $view->render();
    }

    /**
     * Prepare data for the view.
     */
    protected function prepareViewData(): array
    {
        $data = array_filter(
            $this->task->getData(),
            fn ($value) => ! $value instanceof \Closure
        );

        // Add task metadata
        $data['_task'] = [
            'name' => $this->task->getName(),
            'action' => $this->task->getAction(),
            'timeout' => $this->task->getTimeout(),
            'view' => $this->task->getView(),
            'class' => get_class($this->task),
        ];

        // Add environment data
        $data['_env'] = [
            'app_name' => config('app.name'),
            'app_env' => config('app.env'),
            'timestamp' => now()->toISOString(),
        ];

        // Add helper functions
        $data['_helpers'] = $this->getHelperFunctions();

        return $data;
    }

    /**
     * Apply view composers to the view.
     */
    protected function applyViewComposers(BladeView $view): void
    {
        $viewName = $this->task->getView();

        // Apply global task view composers
        $composers = config('task-runner.view.composers', []);

        foreach ($composers as $composer) {
            if (is_callable($composer)) {
                $composer($view, $this->task);
            }
        }

        // Apply specific view composers
        $specificComposers = config("task-runner.view.composers.{$viewName}", []);

        foreach ($specificComposers as $composer) {
            if (is_callable($composer)) {
                $composer($view, $this->task);
            }
        }
    }

    /**
     * Get helper functions for the view.
     */
    protected function getHelperFunctions(): array
    {
        return [
            'escape_shell_arg' => function ($value) {
                return escapeshellarg($value);
            },
            'escape_shell_cmd' => function ($value) {
                return escapeshellcmd($value);
            },
            'format_path' => function ($path) {
                return str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
            },
            'quote_if_needed' => function ($value) {
                return str_contains($value, ' ') ? "'{$value}'" : $value;
            },
            'join_args' => function (array $args) {
                return implode(' ', array_map(fn ($arg) => escapeshellarg($arg), $args));
            },
            'env' => function ($key, $default = null) {
                return env($key, $default);
            },
            'config' => function ($key, $default = null) {
                return config($key, $default);
            },
        ];
    }

    /**
     * Generate cache key for this task view.
     */
    protected function generateCacheKey(): string
    {
        $taskClass = get_class($this->task);
        $viewName = $this->task->getView();
        $data = array_filter(
            $this->task->getData(),
            fn ($value) => ! $value instanceof \Closure
        );
        $dataHash = md5(serialize($data));

        return "task_view:{$taskClass}:{$viewName}:{$dataHash}";
    }

    /**
     * Check if view is cached.
     */
    protected function hasCachedView(): bool
    {
        return Cache::has($this->cacheKey);
    }

    /**
     * Get cached view.
     */
    protected function getCachedView(): string
    {
        return Cache::get($this->cacheKey, '');
    }

    /**
     * Cache the rendered view.
     */
    protected function cacheView(string $script): void
    {
        Cache::put($this->cacheKey, $script, $this->cacheTtl);
    }

    /**
     * Clear the cached view.
     */
    public function clearCache(): void
    {
        Cache::forget($this->cacheKey);
    }

    /**
     * Clear all task view caches.
     */
    public static function clearAllCaches(): void
    {
        Cache::flush();
    }

    /**
     * Get view compilation statistics.
     */
    public function getStats(): array
    {
        return [
            'view_name' => $this->task->getView(),
            'cache_key' => $this->cacheKey,
            'is_cached' => $this->hasCachedView(),
            'cache_enabled' => $this->useCache,
            'cache_ttl' => $this->cacheTtl,
            'data_count' => count($this->task->getData()),
        ];
    }

    /**
     * Validate the view before rendering.
     */
    public function validateView(): void
    {
        $viewName = $this->task->getView();

        if (! View::exists($viewName)) {
            throw new \InvalidArgumentException("View '{$viewName}' does not exist.");
        }

        // Check for potential issues in the view
        $this->checkViewForIssues($viewName);
    }

    /**
     * Check view for potential issues.
     */
    protected function checkViewForIssues(string $viewName): void
    {
        // Get the view file path
        $viewPath = View::getFinder()->find($viewName);

        if (! $viewPath) {
            return;
        }

        $content = file_get_contents($viewPath);

        // Check for common issues
        $issues = [];

        // Check for unescaped variables
        if (preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', $content, $matches)) {
            foreach ($matches[1] as $variable) {
                $var = trim($variable);
                if (! str_contains($var, '|') && ! str_contains($var, 'escape_shell_arg')) {
                    $issues[] = "Unescaped variable: {$var}";
                }
            }
        }

        // Check for potential security issues
        if (preg_match('/\$\{.*\}/', $content)) {
            $issues[] = 'Potential variable expansion detected';
        }

        if (! empty($issues)) {
            throw new \InvalidArgumentException(
                'View validation failed: '.implode(', ', $issues)
            );
        }
    }

    /**
     * Get available views for tasks.
     */
    public static function getAvailableViews(): array
    {
        $viewPath = resource_path('views/'.config('task-runner.task_views', 'tasks'));
        $views = [];

        if (is_dir($viewPath)) {
            $files = glob($viewPath.'/**/*.blade.php');

            foreach ($files as $file) {
                $relativePath = str_replace($viewPath.'/', '', $file);
                $viewName = str_replace('.blade.php', '', $relativePath);
                $views[] = config('task-runner.task_views', 'tasks').'.'.$viewName;
            }
        }

        return $views;
    }

    /**
     * Precompile all task views.
     */
    public static function precompileViews(): array
    {
        $views = self::getAvailableViews();
        $results = [];

        foreach ($views as $viewName) {
            try {
                // Create a mock task to test the view
                $mockTask = new class extends Task
                {
                    public string $view = 'tasks.default';

                    public function getView(): string
                    {
                        return $this->view;
                    }
                };

                $mockTask->view = $viewName;

                $renderer = new self($mockTask);
                $renderer->validateView();

                $results[$viewName] = [
                    'status' => 'success',
                    'message' => 'View compiled successfully',
                ];
            } catch (\Throwable $e) {
                $results[$viewName] = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
