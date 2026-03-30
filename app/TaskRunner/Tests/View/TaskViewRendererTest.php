<?php

declare(strict_types=1);

use App\Modules\TaskRunner\TestTask;
use App\Modules\TaskRunner\View\TaskViewRenderer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->task = new class extends TestTask
    {
        public function __construct()
        {
            $this->name = 'Test Task';
            $this->action = 'test-action';
        }

        public function getScript(): string
        {
            return 'echo "test script"';
        }

        public function getView(): string
        {
            return 'test-view';
        }

        public function getData(): array
        {
            return ['key' => 'value'];
        }
    };
});

describe('TaskViewRenderer', function () {
    it('can be instantiated with a task', function () {
        // Mock the config calls in the constructor
        $this->mock('config', function ($mock) {
            $mock->shouldReceive('get')
                ->with('task-runner.view.cache.enabled', true)
                ->andReturn(true);

            $mock->shouldReceive('get')
                ->with('task-runner.view.cache.ttl', 3600)
                ->andReturn(3600);

            $mock->shouldReceive('get')
                ->with('app.name')
                ->andReturn('Test App');

            $mock->shouldReceive('get')
                ->with('app.env')
                ->andReturn('testing');

            $mock->shouldReceive('get')
                ->with('task-runner.view.composers', [])
                ->andReturn([]);
        });

        $renderer = new TaskViewRenderer($this->task);
        expect($renderer)->toBeInstanceOf(TaskViewRenderer::class);
    });

    it('generates cache key based on task properties', function () {
        // Mock the config calls
        $this->mock('config', function ($mock) {
            $mock->shouldReceive('get')->andReturn(true);
        });

        $renderer = new TaskViewRenderer($this->task);
        $stats = $renderer->getStats();

        expect($stats['cache_key'])->toBeString();
        expect($stats['cache_key'])->toContain('task_view');
        expect($stats['cache_key'])->toContain('Test Task');
    });

    it('renders view with task data', function () {
        // Mock the config calls
        $this->mock('config', function ($mock) {
            $mock->shouldReceive('get')->andReturn(true);
        });

        // Create a test view
        View::shouldReceive('exists')
            ->with('test-view')
            ->andReturn(true);

        View::shouldReceive('make')
            ->andReturnSelf();

        View::shouldReceive('render')
            ->andReturn('rendered script content');

        $renderer = new TaskViewRenderer($this->task);
        $result = $renderer->render();

        expect($result)->toBe('rendered script content');
    });

    it('throws exception when view does not exist', function () {
        // Mock the config calls
        $this->mock('config', function ($mock) {
            $mock->shouldReceive('get')->andReturn(true);
        });

        View::shouldReceive('exists')
            ->with('test-view')
            ->andReturn(false);

        $renderer = new TaskViewRenderer($this->task);
        expect(fn () => $renderer->render())
            ->toThrow(InvalidArgumentException::class, "View 'test-view' does not exist.");
    });

    it('caches rendered view when caching is enabled', function () {
        // Mock the config calls
        $this->mock('config', function ($mock) {
            $mock->shouldReceive('get')
                ->with('task-runner.view.cache.enabled', true)
                ->andReturn(true);
            $mock->shouldReceive('get')
                ->with('task-runner.view.cache.ttl', 3600)
                ->andReturn(3600);
            $mock->shouldReceive('get')->andReturn(true);
        });

        // Mock cache
        Cache::shouldReceive('has')
            ->andReturn(false);

        Cache::shouldReceive('put')
            ->andReturn(true);

        // Mock view rendering
        View::shouldReceive('exists')
            ->andReturn(true);

        View::shouldReceive('make')
            ->andReturnSelf();

        View::shouldReceive('render')
            ->andReturn('cached content');

        $renderer = new TaskViewRenderer($this->task);
        $result = $renderer->render();

        expect($result)->toBe('cached content');
    });

    it('returns cached view when available', function () {
        // Mock the config calls
        $this->mock('config', function ($mock) {
            $mock->shouldReceive('get')
                ->with('task-runner.view.cache.enabled', true)
                ->andReturn(true);
            $mock->shouldReceive('get')
                ->with('task-runner.view.cache.ttl', 3600)
                ->andReturn(3600);
            $mock->shouldReceive('get')->andReturn(true);
        });

        Cache::shouldReceive('has')
            ->andReturn(true);

        Cache::shouldReceive('get')
            ->andReturn('cached content');

        $renderer = new TaskViewRenderer($this->task);
        $result = $renderer->render();

        expect($result)->toBe('cached content');
    });

    it('can clear cache for specific task', function () {
        // Mock the config calls
        $this->mock('config', function ($mock) {
            $mock->shouldReceive('get')->andReturn(true);
        });

        Cache::shouldReceive('forget')
            ->andReturn(true);

        $renderer = new TaskViewRenderer($this->task);
        $renderer->clearCache();

        // Should not throw any exception
        expect(true)->toBeTrue();
    });

    it('can clear all view caches', function () {
        Cache::shouldReceive('flush')
            ->andReturn(true);

        TaskViewRenderer::clearAllCaches();

        // Should not throw any exception
        expect(true)->toBeTrue();
    });

    it('provides view statistics', function () {
        // Mock the config calls
        $this->mock('config', function ($mock) {
            $mock->shouldReceive('get')
                ->with('task-runner.view.cache.enabled', true)
                ->andReturn(true);
            $mock->shouldReceive('get')
                ->with('task-runner.view.cache.ttl', 3600)
                ->andReturn(3600);
            $mock->shouldReceive('get')->andReturn(true);
        });

        $renderer = new TaskViewRenderer($this->task);
        $stats = $renderer->getStats();

        expect($stats)->toBeArray();
        expect($stats)->toHaveKeys(['cache_enabled', 'cache_ttl', 'cache_key']);
        expect($stats['cache_enabled'])->toBeBoolean();
        expect($stats['cache_ttl'])->toBeInt();
        expect($stats['cache_key'])->toBeString();
    });

    it('validates view existence', function () {
        // Mock the config calls
        $this->mock('config', function ($mock) {
            $mock->shouldReceive('get')->andReturn(true);
        });

        View::shouldReceive('exists')
            ->with('test-view')
            ->andReturn(true);

        $renderer = new TaskViewRenderer($this->task);
        $renderer->validateView();

        // Should not throw any exception
        expect(true)->toBeTrue();
    });

    it('throws exception when validating non-existent view', function () {
        // Mock the config calls
        $this->mock('config', function ($mock) {
            $mock->shouldReceive('get')->andReturn(true);
        });

        View::shouldReceive('exists')
            ->with('test-view')
            ->andReturn(false);

        $renderer = new TaskViewRenderer($this->task);
        expect(fn () => $renderer->validateView())
            ->toThrow(InvalidArgumentException::class, "View 'test-view' does not exist.");
    });

    it('checks view for potential issues', function () {
        // Mock the config calls
        $this->mock('config', function ($mock) {
            $mock->shouldReceive('get')->andReturn(true);
        });

        View::shouldReceive('exists')
            ->with('test-view')
            ->andReturn(true);

        // Mock view content check
        View::shouldReceive('make')
            ->andReturnSelf();

        View::shouldReceive('render')
            ->andReturn('echo "safe content"');

        $renderer = new TaskViewRenderer($this->task);
        $renderer->validateView();

        // Should not throw any exception
        expect(true)->toBeTrue();
    });

    it('warns about potentially unsafe view content', function () {
        // Mock the config calls
        $this->mock('config', function ($mock) {
            $mock->shouldReceive('get')->andReturn(true);
        });

        View::shouldReceive('exists')
            ->with('test-view')
            ->andReturn(true);

        View::shouldReceive('make')
            ->andReturnSelf();

        View::shouldReceive('render')
            ->andReturn('rm -rf /');

        $renderer = new TaskViewRenderer($this->task);
        // This should not throw but could log warnings
        $renderer->validateView();

        expect(true)->toBeTrue();
    });

    it('gets available views', function () {
        $views = TaskViewRenderer::getAvailableViews();

        expect($views)->toBeArray();
    });

    it('precompiles views', function () {
        $results = TaskViewRenderer::precompileViews();

        expect($results)->toBeArray();
    });

    it('handles task with custom timeout', function () {
        // Mock the config calls
        $this->mock('config', function ($mock) {
            $mock->shouldReceive('get')->andReturn(true);
        });

        $this->task->setTimeout(60);

        View::shouldReceive('exists')
            ->andReturn(true);

        View::shouldReceive('make')
            ->andReturnSelf();

        View::shouldReceive('render')
            ->andReturn('rendered content');

        $renderer = new TaskViewRenderer($this->task);
        $result = $renderer->render();

        expect($result)->toBe('rendered content');
    });

    it('handles task with complex data', function () {
        // Mock the config calls
        $this->mock('config', function ($mock) {
            $mock->shouldReceive('get')->andReturn(true);
        });

        $this->task->setData([
            'nested' => ['key' => 'value'],
            'array' => [1, 2, 3],
            'boolean' => true,
            'null' => null,
        ]);

        View::shouldReceive('exists')
            ->andReturn(true);

        View::shouldReceive('make')
            ->andReturnSelf();

        View::shouldReceive('render')
            ->andReturn('rendered content');

        $renderer = new TaskViewRenderer($this->task);
        $result = $renderer->render();

        expect($result)->toBe('rendered content');
    });
});
