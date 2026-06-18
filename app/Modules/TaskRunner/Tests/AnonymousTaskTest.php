<?php

declare(strict_types=1);

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Exceptions\TaskValidationException;
use App\Modules\TaskRunner\Facades\TaskRunner;
use Tests\TestCase;

uses(TestCase::class);

describe('AnonymousTask', function () {
    beforeEach(function () {
        TaskRunner::fake();
    });

    afterEach(function () {
        TaskRunner::unfake();
    });

    describe('Constructor', function () {
        it('can be constructed with default attributes', function () {
            $task = new AnonymousTask;
            expect($task->getName())->toBe('Anonymous Task')
                ->and($task->getAction())->toBe('anonymous')
                ->and($task->getTimeout())->toBeNull()
                ->and($task->getView())->toBe('anonymous-task')
                ->and($task->getViewData())->toBe([]);
        });

        it('can be constructed with custom attributes', function () {
            $task = new AnonymousTask([
                'name' => 'Custom Task',
                'action' => 'custom_action',
                'timeout' => 120,
                'view' => 'tasks.custom',
                'data' => ['key' => 'value'],
                'script' => 'echo "custom"',
            ]);
            expect($task->getName())->toBe('Custom Task')
                ->and($task->getAction())->toBe('custom_action')
                ->and($task->getTimeout())->toBe(120)
                ->and($task->getView())->toBe('tasks.custom')
                ->and($task->getData())->toHaveKey('key')
                ->and($task->getScript())->toBe('echo "custom"');
        });

        it('sets public properties from data array', function () {
            $task = new AnonymousTask([
                'data' => [
                    'customProperty' => 'customValue',
                    'anotherProperty' => 42,
                ],
            ]);
            expect($task->{'customProperty'})->toBe('customValue')
                ->and($task->{'anotherProperty'})->toBe(42);
        });
    });

    describe('Getter Methods', function () {
        it('getName returns the task name', function () {
            $task = new AnonymousTask(['name' => 'Test Name']);
            expect($task->getName())->toBe('Test Name');
        });

        it('getAction returns the task action', function () {
            $task = new AnonymousTask(['action' => 'test_action']);
            expect($task->getAction())->toBe('test_action');
        });

        it('getTimeout returns the task timeout', function () {
            $task = new AnonymousTask(['timeout' => 300]);
            expect($task->getTimeout())->toBe(300);
        });

        it('getView returns custom view when set', function () {
            $task = new AnonymousTask(['view' => 'tasks.custom']);
            expect($task->getView())->toBe('tasks.custom');
        });

        it('getView returns parent view when not set', function () {
            $task = new AnonymousTask;
            expect($task->getView())->toContain('anonymous-task');
        });

        it('getData merges parent and instance data', function () {
            $task = new AnonymousTask(['data' => ['instance' => 'value']]);
            $data = $task->getData();
            expect($data)->toHaveKey('instance');
        });

        it('getViewData returns only instance data', function () {
            $task = new AnonymousTask(['data' => ['view' => 'data']]);
            expect($task->getViewData())->toBe(['view' => 'data']);
        });

        it('getScript returns direct script when set', function () {
            $task = new AnonymousTask(['script' => 'echo "direct"']);
            expect($task->getScript())->toBe('echo "direct"');
        });

        it('getScript calls render callback when set', function () {
            $callback = function ($task) {
                return 'echo "callback"';
            };
            $task = new AnonymousTask(['render_callback' => $callback]);
            expect($task->getScript())->toBe('echo "callback"');
        });
    });

    describe('Setter Methods', function () {
        it('setName sets the task name', function () {
            $task = new AnonymousTask;
            $task->setName('New Name');
            expect($task->getName())->toBe('New Name');
        });

        it('setAction sets the task action', function () {
            $task = new AnonymousTask;
            $task->setAction('new_action');
            expect($task->getAction())->toBe('new_action');
        });

        it('setTimeout sets the task timeout', function () {
            $task = new AnonymousTask;
            $task->setTimeout(600);
            expect($task->getTimeout())->toBe(600);
        });

        it('setTimeout accepts null', function () {
            $task = new AnonymousTask(['timeout' => 300]);
            $task->setTimeout(null);
            expect($task->getTimeout())->toBeNull();
        });

        it('setView sets the task view', function () {
            $task = new AnonymousTask;
            $task->setView('tasks.new');
            expect($task->getView())->toBe('tasks.new');
        });

        it('setView accepts null', function () {
            $task = new AnonymousTask(['view' => 'tasks.old']);
            $task->setView(null);
            expect($task->getView())->not()->toBe('tasks.old');
        });

        it('setScript sets the task script', function () {
            $task = new AnonymousTask;
            $task->setScript('echo "new script"');
            expect($task->getScript())->toBe('echo "new script"');
        });

        it('setScript accepts null', function () {
            $task = new AnonymousTask(['script' => 'echo "old"']);
            $task->setScript(null);
            expect(fn () => $task->getScript())
                ->toThrow(TaskValidationException::class);
        });

        it('setRenderCallback sets the callback', function () {
            $task = new AnonymousTask;
            $callback = function ($task) {
                return 'echo "callback"';
            };
            $task->setRenderCallback($callback);
            expect($task->getScript())->toBe('echo "callback"');
        });

        it('setRenderCallback accepts null', function () {
            $task = new AnonymousTask(['script' => 'echo "direct"']);
            $task->setRenderCallback(null);
            expect($task->getScript())->toBe('echo "direct"');
        });
    });

    describe('Data Manipulation Methods', function () {
        it('addData merges new data with existing data', function () {
            $task = new AnonymousTask(['data' => ['existing' => 'value']]);
            $task->addData(['new' => 'data', 'another' => 'value']);
            expect($task->getData())->toHaveKey('existing')
                ->and($task->getData())->toHaveKey('new')
                ->and($task->getData())->toHaveKey('another');
        });

        it('setData sets a single data value', function () {
            $task = new AnonymousTask;
            $task->setData('key', 'value');
            expect($task->getData())->toHaveKey('key')
                ->and($task->getData()['key'])->toBe('value');
        });

        it('setData overwrites existing data', function () {
            $task = new AnonymousTask(['data' => ['key' => 'old']]);
            $task->setData('key', 'new');
            expect($task->getData()['key'])->toBe('new');
        });
    });

    describe('Static Constructor Methods', function () {
        describe('script()', function () {
            it('creates task with script', function () {
                $task = AnonymousTask::script('Script Task', 'echo "script"');
                expect($task->getName())->toBe('Script Task')
                    ->and($task->getAction())->toBe('script')
                    ->and($task->getScript())->toBe('echo "script"');
            });

            it('accepts additional options', function () {
                $task = AnonymousTask::script('Script Task', 'echo "script"', ['timeout' => 120]);
                expect($task->getTimeout())->toBe(120);
            });
        });

        describe('command()', function () {
            it('creates task with command script', function () {
                $task = AnonymousTask::command('Command Task', 'ls -la');
                expect($task->getName())->toBe('Command Task')
                    ->and($task->getScript())->toContain('ls -la')
                    ->and($task->getScript())->toContain('Starting: Command Task')
                    ->and($task->getScript())->toContain('Completed: Command Task');
            });

            it('includes proper bash header', function () {
                $task = AnonymousTask::command('Test', 'echo test');
                expect($task->getScript())->toContain('#!/bin/bash')
                    ->and($task->getScript())->toContain('set -euo pipefail');
            });
        });

        describe('commands()', function () {
            it('creates task with multiple commands', function () {
                $task = AnonymousTask::commands('Multi Task', ['whoami', 'pwd']);
                expect($task->getScript())->toContain('whoami')
                    ->and($task->getScript())->toContain('pwd')
                    ->and($task->getScript())->toContain('Executing: whoami')
                    ->and($task->getScript())->toContain('Executing: pwd');
            });

            it('handles empty commands array', function () {
                $task = AnonymousTask::commands('Empty Task', []);
                expect($task->getScript())->toContain('Starting: Empty Task')
                    ->and($task->getScript())->toContain('Completed: Empty Task');
            });
        });

        describe('view()', function () {
            it('creates task with view', function () {
                $task = AnonymousTask::view('View Task', 'tasks.example', ['key' => 'value']);
                expect($task->getName())->toBe('View Task')
                    ->and($task->getAction())->toBe('view')
                    ->and($task->getView())->toBe('tasks.example')
                    ->and($task->getViewData())->toHaveKey('key');
            });

            it('handles empty data array', function () {
                $task = AnonymousTask::view('View Task', 'tasks.example');
                expect($task->getViewData())->toBe([]);
            });
        });

        describe('callback()', function () {
            it('creates task with callback', function () {
                $callback = function ($task) {
                    return 'echo "callback output"';
                };
                $task = AnonymousTask::callback('Callback Task', $callback);
                expect($task->getName())->toBe('Callback Task')
                    ->and($task->getAction())->toBe('callback')
                    ->and($task->getScript())->toBe('echo "callback output"');
            });

            it('passes task instance to callback', function () {
                $callback = function ($task) {
                    return 'echo "'.$task->getName().'"';
                };
                $task = AnonymousTask::callback('Test Task', $callback);
                expect($task->getScript())->toBe('echo "Test Task"');
            });
        });

        describe('inline()', function () {
            it('creates task with inline script', function () {
                $task = AnonymousTask::inline('Inline Task', 'echo "inline"');
                expect($task->getName())->toBe('Inline Task')
                    ->and($task->getScript())->toBe('echo "inline"');
            });

            it('is alias for script method', function () {
                $inlineTask = AnonymousTask::inline('Test', 'echo test');
                $scriptTask = AnonymousTask::script('Test', 'echo test');
                expect($inlineTask->getScript())->toBe($scriptTask->getScript());
            });
        });

        describe('withEnv()', function () {
            it('creates task with environment variables', function () {
                $task = AnonymousTask::withEnv('Env Task', ['FOO' => 'bar', 'BAZ' => 'qux'], 'echo $FOO');
                expect($task->getScript())->toContain('export FOO="bar"')
                    ->and($task->getScript())->toContain('export BAZ="qux"')
                    ->and($task->getScript())->toContain('echo $FOO');
            });

            it('handles empty environment array', function () {
                $task = AnonymousTask::withEnv('Env Task', [], 'echo test');
                expect($task->getScript())->toContain('echo test')
                    ->and($task->getScript())->not()->toContain('export');
            });
        });

        describe('conditional()', function () {
            it('creates task with conditional logic', function () {
                $task = AnonymousTask::conditional('Cond Task', [
                    '[ -f /tmp/file ]' => 'echo "exists"',
                    '[ -d /var/log ]' => 'echo "directory"',
                ]);
                expect($task->getScript())->toContain('if [ -f /tmp/file ]; then')
                    ->and($task->getScript())->toContain('echo "exists"')
                    ->and($task->getScript())->toContain('if [ -d /var/log ]; then')
                    ->and($task->getScript())->toContain('echo "directory"');
            });

            it('handles array of commands in condition', function () {
                $task = AnonymousTask::conditional('Cond Task', [
                    '[ -f /tmp/file ]' => ['echo "step1"', 'echo "step2"'],
                ]);
                expect($task->getScript())->toContain('echo "step1"')
                    ->and($task->getScript())->toContain('echo "step2"');
            });
        });

        describe('withErrorHandling()', function () {
            it('creates task with error handling', function () {
                $task = AnonymousTask::withErrorHandling('Error Task', 'false', 'echo "cleanup"');
                expect($task->getScript())->toContain('if false; then')
                    ->and($task->getScript())->toContain('echo "cleanup"')
                    ->and($task->getScript())->toContain('exit 1');
            });

            it('handles null error command', function () {
                $task = AnonymousTask::withErrorHandling('Error Task', 'false');
                expect($task->getScript())->toContain('if false; then')
                    ->and($task->getScript())->not()->toContain('echo "cleanup"');
            });
        });

        describe('withRetry()', function () {
            it('creates task with retry logic', function () {
                $task = AnonymousTask::withRetry('Retry Task', 'false', 3, 2);
                expect($task->getScript())->toContain('for attempt in $(seq 1 3); do')
                    ->and($task->getScript())->toContain('sleep 2')
                    ->and($task->getScript())->toContain('if false; then');
            });

            it('uses default retry parameters', function () {
                $task = AnonymousTask::withRetry('Retry Task', 'false');
                expect($task->getScript())->toContain('for attempt in $(seq 1 3); do')
                    ->and($task->getScript())->toContain('sleep 5');
            });
        });

        describe('withProgress()', function () {
            it('creates task with progress tracking', function () {
                $task = AnonymousTask::withProgress('Progress Task', [
                    'Step 1' => 'echo "step1"',
                    'Step 2' => 'echo "step2"',
                ]);
                expect($task->getScript())->toContain('[PROGRESS] Step 1/2 (50%): Step 1')
                    ->and($task->getScript())->toContain('[PROGRESS] Step 2/2 (100%): Step 2')
                    ->and($task->getScript())->toContain('echo "step1"')
                    ->and($task->getScript())->toContain('echo "step2"');
            });

            it('handles single step', function () {
                $task = AnonymousTask::withProgress('Single Task', ['Step 1' => 'echo "single"']);
                expect($task->getScript())->toContain('[PROGRESS] Step 1/1 (100%): Step 1');
            });
        });

        describe('withCleanup()', function () {
            it('creates task with cleanup', function () {
                $task = AnonymousTask::withCleanup('Cleanup Task', 'echo "do work"', 'echo "cleanup"');
                expect($task->getScript())->toContain('trap')
                    ->and($task->getScript())->toContain('echo "cleanup"')
                    ->and($task->getScript())->toContain('echo "do work"');
            });
        });

        describe('withLogging()', function () {
            it('creates task with logging', function () {
                $task = AnonymousTask::withLogging('Log Task', 'echo "log this"');
                expect($task->getScript())->toContain('LOG_FILE=')
                    ->and($task->getScript())->toContain('echo "log this"')
                    ->and($task->getScript())->toContain('tee -a');
            });

            it('uses custom log file when provided', function () {
                $task = AnonymousTask::withLogging('Log Task', 'echo "log"', '/custom/log.txt');
                expect($task->getScript())->toContain('LOG_FILE="/custom/log.txt"');
            });
        });
    });

    describe('Integration with TaskRunner', function () {
        it('runs with TaskRunner::runAnonymous and returns fake output', function () {
            $task = AnonymousTask::command('Run Task', 'echo "run"');
            $result = TaskRunner::runAnonymous($task);
            expect($result)->not()->toBeNull()
                ->and($result->getExitCode())->toBe(0)
                ->and($result->getBuffer())->toBe(''); // Default fake output is empty string
        });

        it('runs script task with TaskRunner', function () {
            $task = AnonymousTask::script('Script Task', 'echo "script output"');
            $result = TaskRunner::runAnonymous($task);
            expect($result)->not()->toBeNull()
                ->and($result->getExitCode())->toBe(0);
        });

        it('runs callback task with TaskRunner', function () {
            $task = AnonymousTask::callback('Callback Task', function () {
                return 'echo "callback executed"';
            });
            $result = TaskRunner::runAnonymous($task);
            expect($result)->not()->toBeNull()
                ->and($result->getExitCode())->toBe(0);
        });

        it('runs complex task with TaskRunner', function () {
            $task = AnonymousTask::withProgress('Complex Task', [
                'Step 1' => 'echo "step1"',
                'Step 2' => 'echo "step2"',
            ]);
            $result = TaskRunner::runAnonymous($task);
            expect($result)->not()->toBeNull()
                ->and($result->getExitCode())->toBe(0);
        });
    });

    describe('Method Chaining', function () {
        it('supports method chaining for setters', function () {
            $task = AnonymousTask::script('Chain Task', 'echo test')
                ->setName('New Name')
                ->setAction('new_action')
                ->setTimeout(120)
                ->setView('tasks.new')
                ->addData(['key' => 'value'])
                ->setData('another', 'data');

            expect($task->getName())->toBe('New Name')
                ->and($task->getAction())->toBe('new_action')
                ->and($task->getTimeout())->toBe(120)
                ->and($task->getView())->toBe('tasks.new')
                ->and($task->getData())->toHaveKey('key')
                ->and($task->getData())->toHaveKey('another');
        });
    });

    describe('Edge Cases', function () {
        it('handles empty script in constructor', function () {
            $task = new AnonymousTask(['script' => '']);
            expect($task->getScript())->toBe('');
        });

        it('handles null script in constructor', function () {
            $task = new AnonymousTask(['script' => null]);
            expect(fn () => $task->getScript())
                ->toThrow(TaskValidationException::class);
        });

        it('handles empty data in constructor', function () {
            $task = new AnonymousTask(['data' => []]);
            expect($task->getViewData())->toBe([]);
        });

        it('handles null timeout in constructor', function () {
            $task = new AnonymousTask(['timeout' => null]);
            expect($task->getTimeout())->toBeNull();
        });

        it('handles zero timeout in constructor', function () {
            $task = new AnonymousTask(['timeout' => 0]);
            expect($task->getTimeout())->toBeNull();
        });

        it('handles negative timeout in constructor', function () {
            $task = new AnonymousTask(['timeout' => -1]);
            expect($task->getTimeout())->toBeNull();
        });
    });

    describe('Additional Edge and Behavior Tests', function () {
        it('setData with numeric key works', function () {
            $task = new AnonymousTask;
            $task->setData('123', 'numkey');
            expect($task->getViewData())->toHaveKey('123')->and($task->getViewData()['123'])->toBe('numkey');
        });

        it('addData overwrites existing keys', function () {
            $task = new AnonymousTask(['data' => ['foo' => 'bar']]);
            $task->addData(['foo' => 'baz']);
            expect($task->getViewData()['foo'])->toBe('baz');
        });

        it('setScript takes precedence over setRenderCallback', function () {
            $task = new AnonymousTask;
            $task->setScript('echo script')->setRenderCallback(fn () => 'echo cb');
            expect($task->getScript())->toBe('echo script');
        });

        it('setRenderCallback then setScript uses script', function () {
            $task = new AnonymousTask;
            $task->setRenderCallback(fn () => 'echo cb')->setScript('echo script');
            expect($task->getScript())->toBe('echo script');
        });

        it('script constructor with empty string', function () {
            $task = AnonymousTask::script('Empty Script', '');
            expect($task->getScript())->toBe('');
        });

        it('command with empty command string', function () {
            $task = AnonymousTask::command('No Command', '');
            expect($task->getScript())->toContain('Starting: No Command');
        });

        it('commands with one command', function () {
            $task = AnonymousTask::commands('Single', ['echo only']);
            expect($task->getScript())->toContain('echo only');
        });

        it('withEnv with special characters in env', function () {
            $task = AnonymousTask::withEnv('Special Env', ['FOO' => 'b@r!'], 'echo $FOO');
            expect($task->getScript())->toContain('export FOO="b@r!"');
        });

        it('conditional with default key', function () {
            $task = AnonymousTask::conditional('Default Cond', ['default' => 'echo default']);
            expect($task->getScript())->toContain('echo default');
        });

        it('withLogging with null log file uses /tmp', function () {
            $task = AnonymousTask::withLogging('LogNull', 'echo log');
            expect($task->getScript())->toContain('LOG_FILE="/tmp/LogNull.log"');
        });
    });
});

describe('Advanced and Edge Case Tests', function () {
    it('script() with a very long script', function () {
        $longScript = str_repeat('echo line;', 1000);
        $task = AnonymousTask::script('Long Script', $longScript);
        expect($task->getScript())->toContain('echo line;');
    });

    it('script() with unicode characters', function () {
        $unicodeScript = 'echo "こんにちは世界"';
        $task = AnonymousTask::script('Unicode Script', $unicodeScript);
        expect($task->getScript())->toContain('こんにちは世界');
    });

    it('command() with special shell characters', function () {
        $cmd = 'echo $PATH && ls | grep ".php"';
        $task = AnonymousTask::command('Special Shell', $cmd);
        expect($task->getScript())->toContain('ls | grep');
    });

    it('commands() with duplicate commands', function () {
        $task = AnonymousTask::commands('Dupes', ['echo 1', 'echo 1']);
        expect(substr_count($task->getScript(), 'echo 1'))->toBeGreaterThan(1);
    });

    it('commands() with commands containing newlines', function () {
        $task = AnonymousTask::commands('Newlines', ["echo 'a\nb'", "echo 'c\nd'"]);
        expect($task->getScript())->toContain("echo 'a\nb'");
        expect($task->getScript())->toContain("echo 'c\nd'");
    });

    it('view() with a non-existent view', function () {
        $task = AnonymousTask::view('NoView', 'not.a.real.view');
        expect($task->getView())->toBe('not.a.real.view');
    });

    it('callback() with a callback that throws', function () {
        $task = AnonymousTask::callback('Throws', function () {
            throw new Exception('fail');
        });
        expect(fn () => $task->getScript())->toThrow(Exception::class);
    });

    it('callback() with a callback that returns empty string', function () {
        $task = AnonymousTask::callback('Empty', fn () => '');
        expect($task->getScript())->toBe('');
    });

    it('inline() with a script that is just whitespace', function () {
        $task = AnonymousTask::inline('Whitespace', '   ');
        expect($task->getScript())->toBe('   ');
    });

    it('withEnv() with invalid shell identifiers', function () {
        $task = AnonymousTask::withEnv('BadEnv', ['1FOO' => 'bar', 'BAZ-BAD' => 'qux'], 'echo $1FOO $BAZ-BAD');
        expect($task->getScript())->toContain('export 1FOO="bar"');
        expect($task->getScript())->toContain('export BAZ-BAD="qux"');
    });

    it('withEnv() with empty command', function () {
        $task = AnonymousTask::withEnv('EmptyCmd', ['FOO' => 'bar'], '');
        expect($task->getScript())->toContain('export FOO="bar"');
    });

    it('conditional() with empty conditions array', function () {
        $task = AnonymousTask::conditional('NoConds', []);
        expect($task->getScript())->toContain('Starting: NoConds');
    });

    it('conditional() with always false condition', function () {
        $task = AnonymousTask::conditional('FalseCond', ['false' => 'echo never']);
        expect($task->getScript())->toContain('if false; then');
    });

    it('withErrorHandling() with both commands empty', function () {
        $task = AnonymousTask::withErrorHandling('EmptyErr', '', '');
        expect($task->getScript())->toContain('if ; then');
    });

    it('withRetry() with zero retries', function () {
        $task = AnonymousTask::withRetry('ZeroRetry', 'echo ok', 0);
        expect($task->getScript())->toContain('seq 1 0');
    });

    it('withRetry() with negative delay', function () {
        $task = AnonymousTask::withRetry('NegDelay', 'echo ok', 2, -5);
        expect($task->getScript())->toContain('sleep -5');
    });

    it('withProgress() with non-associative steps', function () {
        $task = AnonymousTask::withProgress('NonAssoc', ['echo a', 'echo b']);
        expect($task->getScript())->toContain('echo a');
        expect($task->getScript())->toContain('echo b');
    });

    it('withCleanup() with empty cleanup command', function () {
        $task = AnonymousTask::withCleanup('NoCleanup', 'echo work', '');
        expect($task->getScript())->toContain('Running cleanup...');
    });

    it('withLogging() with special chars in log file', function () {
        $task = AnonymousTask::withLogging('Log$#@!', 'echo log', '/tmp/log$#@!.txt');
        expect($task->getScript())->toContain('LOG_FILE="/tmp/log$#@!.txt"');
    });

    it('setName() with a very long name', function () {
        $longName = str_repeat('A', 256);
        $task = new AnonymousTask;
        $task->setName($longName);
        expect($task->getName())->toBe($longName);
    });

    it('setAction() with special characters', function () {
        $task = new AnonymousTask;
        $task->setAction('action!@#');
        expect($task->getAction())->toBe('action!@#');
    });

    it('setTimeout() with value above allowed maximum', function () {
        $task = new AnonymousTask;
        $task->setTimeout(9999);
        expect($task->getTimeout())->toBe(9999);
    });

    it('setTimeout() with value below allowed minimum', function () {
        $task = new AnonymousTask;
        $task->setTimeout(-999);
        expect($task->getTimeout())->toBe(-999);
    });

    it('setView() with unicode', function () {
        $task = new AnonymousTask;
        $task->setView('ビュー.タスク');
        expect($task->getView())->toBe('ビュー.タスク');
    });

    it('setScript() with PHP code', function () {
        $phpScript = '<?php echo "hi";';
        $task = new AnonymousTask;
        $task->setScript($phpScript);
        expect($task->getScript())->toBe($phpScript);
    });

    it('setRenderCallback() with callback that returns null', function () {
        $task = new AnonymousTask;
        $task->setRenderCallback(fn () => null);
        expect(fn () => $task->getScript())->toThrow(TypeError::class);
    });

    it('addData() with deeply nested arrays', function () {
        $task = new AnonymousTask;
        $nested = ['a' => ['b' => ['c' => ['d' => 'e']]]];
        $task->addData($nested);
        expect($task->getViewData())->toHaveKey('a');
        expect($task->getViewData()['a']['b']['c']['d'])->toBe('e');
    });

    it('setData() with key that overwrites public property', function () {
        $task = new AnonymousTask;
        $task->setData('name', 'Overwritten');
        expect($task->getViewData()['name'])->toBe('Overwritten');
    });

    it('getData() after multiple mutations', function () {
        $task = new AnonymousTask;
        $task->setData('foo', 'bar')->addData(['baz' => 'qux']);
        $data = $task->getData();
        expect($data)->toHaveKey('foo')->and($data)->toHaveKey('baz');
    });
});
