<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Task;
use App\Modules\TaskRunner\Tasks\GenerateEd25519KeyPair;
use App\Modules\TaskRunner\View\TaskViewRenderer;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TaskViewRenderer::clearAllCaches();
});

describe('GenerateEd25519KeyPair', function () {
    it('sets the view property correctly', function () {
        $task = new GenerateEd25519KeyPair('/tmp/testkey');
        expect($task->view)->toBe('task-runner::tasks.generate-ed25519-key-pair');
    });

    it('sets the privatePath property from constructor', function () {
        $task = new GenerateEd25519KeyPair('/tmp/testkey');
        expect($task->privatePath)->toBe('/tmp/testkey');
    });

    it('returns the correct comment', function () {
        $task = new GenerateEd25519KeyPair('/tmp/testkey');
        expect($task->comment())->toBe('dply@dply.io');
    });

    it('accepts unusual but valid file paths', function () {
        $paths = [
            '/tmp/with space.key',
            '/tmp/测试.key',
            '/tmp/.hiddenfile',
            '/tmp/key-!@#$%^&*().key',
            '/tmp/key_with_underscore.key',
            '/tmp/key-with-dash.key',
        ];
        foreach ($paths as $path) {
            $task = new GenerateEd25519KeyPair($path);
            expect($task->privatePath)->toBe($path);
        }
    });

    it('allows changing the public property privatePath after construction', function () {
        $task = new GenerateEd25519KeyPair('/tmp/initial');
        $task->privatePath = '/tmp/changed';
        expect($task->privatePath)->toBe('/tmp/changed');
    });

    it('always returns the same comment regardless of input', function () {
        $task1 = new GenerateEd25519KeyPair('/tmp/a');
        $task2 = new GenerateEd25519KeyPair('/tmp/b');
        expect($task1->comment())->toBe('dply@dply.io');
        expect($task2->comment())->toBe('dply@dply.io');
    });

    it('view property remains correct after property mutation', function () {
        $task = new GenerateEd25519KeyPair('/tmp/initial');
        $task->view = 'changed-view';
        expect($task->view)->toBe('changed-view');
    });

    it('enforces string type for privatePath in constructor', function () {
        // @phpstan-ignore-next-line
        // @psalm-suppress InvalidArgument
        expect(fn () => new GenerateEd25519KeyPair(123))->toThrow(TypeError::class);
        // @phpstan-ignore-next-line
        // @psalm-suppress InvalidArgument
        expect(fn () => new GenerateEd25519KeyPair(null))->toThrow(TypeError::class);
        // @phpstan-ignore-next-line
        // @psalm-suppress InvalidArgument
        expect(fn () => new GenerateEd25519KeyPair([]))->toThrow(TypeError::class);
    });

    it('inherits from Task', function () {
        $task = new GenerateEd25519KeyPair('/tmp/testkey');
        expect($task)->toBeInstanceOf(Task::class);
    });

    it('handles empty string as privatePath', function () {
        $task = new GenerateEd25519KeyPair('');
        expect($task->privatePath)->toBe('');
    });

    it('multiple instances with different paths are independent', function () {
        $a = new GenerateEd25519KeyPair('/tmp/a');
        $b = new GenerateEd25519KeyPair('/tmp/b');
        expect($a->privatePath)->toBe('/tmp/a');
        expect($b->privatePath)->toBe('/tmp/b');
        $a->privatePath = '/tmp/changed';
        expect($b->privatePath)->toBe('/tmp/b');
    });

    it('two instances with same path are not the same object', function () {
        $a = new GenerateEd25519KeyPair('/tmp/same');
        $b = new GenerateEd25519KeyPair('/tmp/same');
        expect($a)->not->toBe($b);
    });

    it('can set privatePath via reflection', function () {
        $task = new GenerateEd25519KeyPair('/tmp/initial');
        $ref = new ReflectionProperty($task, 'privatePath');
        $ref->setValue($task, '/tmp/reflect');
        expect($task->privatePath)->toBe('/tmp/reflect');
    });

    it('can serialize and unserialize the object', function () {
        $task = new GenerateEd25519KeyPair('/tmp/serial');
        $ser = serialize($task);
        $unser = unserialize($ser);
        expect($unser)->toBeInstanceOf(GenerateEd25519KeyPair::class);
        expect($unser->privatePath)->toBe('/tmp/serial');
    });

    it('can clone the object', function () {
        $task = new GenerateEd25519KeyPair('/tmp/clone');
        $clone = clone $task;
        expect($clone)->toBeInstanceOf(GenerateEd25519KeyPair::class);
        expect($clone->privatePath)->toBe('/tmp/clone');
        expect($clone)->not->toBe($task);
    });

    it('public properties exist', function () {
        $task = new GenerateEd25519KeyPair('/tmp/check');
        expect(property_exists($task, 'view'))->toBeTrue();
        expect(property_exists($task, 'privatePath'))->toBeTrue();
    });

    it('view property is set by default', function () {
        $task = new GenerateEd25519KeyPair('/tmp/check');
        expect($task->view)->toBe('task-runner::tasks.generate-ed25519-key-pair');
    });

    it('adding dynamic property does not affect core behavior', function () {
        $task = new GenerateEd25519KeyPair('/tmp/dyn');
        // @phpstan-ignore-next-line
        // @psalm-suppress UndefinedProperty
        $task->extra = 'value';
        expect($task->privatePath)->toBe('/tmp/dyn');
        // @phpstan-ignore-next-line
        // @psalm-suppress UndefinedProperty
        expect($task->extra)->toBe('value');
    });

    it('parent class is Task', function () {
        $task = new GenerateEd25519KeyPair('/tmp/parent');
        expect(get_parent_class($task))->toBe(Task::class);
    });

    it('getName() returns class name in headline format', function () {
        $task = new GenerateEd25519KeyPair('/tmp/name');
        expect($task->getName())->toBe('Generate Ed25519 Key Pair');
    });

    it('getView() returns correct view', function () {
        $task = new GenerateEd25519KeyPair('/tmp/view');
        expect($task->getView())->toBe('task-runner::tasks.generate-ed25519-key-pair');
    });

    it('getData() includes privatePath', function () {
        $task = new GenerateEd25519KeyPair('/tmp/data');
        $data = $task->getData();
        expect($data['privatePath'] ?? null)->toBe('/tmp/data');
    });

    it('getPublicProperties() includes privatePath', function () {
        $task = new GenerateEd25519KeyPair('/tmp/properties');
        $props = $task->getPublicProperties();
        expect($props->has('privatePath'))->toBeTrue();
    });

    it('getPublicMethods() includes comment', function () {
        $task = new GenerateEd25519KeyPair('/tmp/methods');
        $methods = $task->getPublicMethods();
        expect($methods->has('comment'))->toBeTrue();
    });

    it('validates script and executes the task', function () {
        $task = new GenerateEd25519KeyPair('/tmp/testkey');
        $script = $task->getScript();
        expect($script)->toContain('ssh-keygen -t ed25519');
        // Simulate execution (handle will just set output to script in this context)
        $task->handle();
        expect($task->getOutput())->toContain('ssh-keygen -t ed25519');
    });

});
