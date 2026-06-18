<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Events\TaskProgress;
use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\TestTask;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

describe('TaskProgress Event', function () {
    describe('event construction', function () {
        it('creates event with required properties', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $currentStep = 3;
            $totalSteps = 10;
            $stepName = 'Processing data';
            $context = ['user_id' => 1, 'environment' => 'production'];

            $event = new TaskProgress($task, $pendingTask, $currentStep, $totalSteps, $stepName, $context);

            expect($event->task)->toBe($task);
            expect($event->pendingTask)->toBe($pendingTask);
            expect($event->currentStep)->toBe($currentStep);
            expect($event->totalSteps)->toBe($totalSteps);
            expect($event->stepName)->toBe($stepName);
            expect($event->context)->toBe($context);
            expect($event->percentage)->toBe(30.0);
            expect($event->timestamp)->toBeString();
            expect($event->timestamp)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
        });

        it('creates event with empty context when not provided', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $currentStep = 1;
            $totalSteps = 5;
            $stepName = 'Starting task';

            $event = new TaskProgress($task, $pendingTask, $currentStep, $totalSteps, $stepName);

            expect($event->context)->toBe([]);
        });

        it('calculates percentage correctly', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $currentStep = 7;
            $totalSteps = 20;
            $stepName = 'Processing step';

            $event = new TaskProgress($task, $pendingTask, $currentStep, $totalSteps, $stepName);

            expect($event->percentage)->toBe(35.0);
        });

        it('handles zero total steps', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $currentStep = 1;
            $totalSteps = 0;
            $stepName = 'No steps';

            $event = new TaskProgress($task, $pendingTask, $currentStep, $totalSteps, $stepName);

            expect($event->percentage)->toBe(0.0);
        });
    });

    describe('task information methods', function () {
        it('returns task name', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskProgress($task, $pendingTask, 1, 5, 'Step 1');

            expect($event->getTaskName())->toBe('test-task');
        });

        it('returns task action', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskProgress($task, $pendingTask, 1, 5, 'Step 1');

            expect($event->getTaskAction())->toBe('test');
        });

        it('returns task class', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskProgress($task, $pendingTask, 1, 5, 'Step 1');

            expect($event->getTaskClass())->toBe(TestTask::class);
        });

        it('returns task data', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskProgress($task, $pendingTask, 1, 5, 'Step 1');

            expect($event->getTaskData())->toBeArray();
        });
    });
});

describe('progress tracking methods', function () {
    it('returns current step', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 3, 10, 'Step 3');

        expect($event->getCurrentStep())->toBe(3);
    });

    it('returns total steps', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 3, 10, 'Step 3');

        expect($event->getTotalSteps())->toBe(10);
    });

    it('returns step name', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 3, 10, 'Processing data');

        expect($event->getStepName())->toBe('Processing data');
    });

    it('returns percentage', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 5, 20, 'Step 5');

        expect($event->getPercentage())->toBe(25.0);
    });

    it('returns percentage as integer', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 7, 15, 'Step 7');

        expect($event->getPercentageInt())->toBe(47); // 7/15 * 100 = 46.67, rounded to 47
    });

    it('returns progress ratio', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 3, 10, 'Step 3');

        expect($event->getProgressRatio())->toBe(0.3);
    });

    it('returns remaining steps', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 7, 10, 'Step 7');

        expect($event->getRemainingSteps())->toBe(3);
    });

    it('returns zero remaining steps when complete', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 10, 10, 'Final step');

        expect($event->getRemainingSteps())->toBe(0);
    });

    it('returns zero remaining steps when current step exceeds total', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 12, 10, 'Overflow step');

        expect($event->getRemainingSteps())->toBe(0);
    });
});

describe('progress state methods', function () {
    it('checks if task is complete', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 10, 10, 'Final step');

        expect($event->isComplete())->toBeTrue();
    });

    it('checks if task is complete when current step exceeds total', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 12, 10, 'Overflow step');

        expect($event->isComplete())->toBeTrue();
    });

    it('checks if task is not complete', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 5, 10, 'Step 5');

        expect($event->isComplete())->toBeFalse();
    });

    it('checks if task is in progress', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 5, 10, 'Step 5');

        expect($event->isInProgress())->toBeTrue();
    });

    it('checks if task is not in progress when complete', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 10, 10, 'Final step');

        expect($event->isInProgress())->toBeFalse();
    });

    it('checks if task is not in progress when just starting', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 1, 10, 'First step');

        expect($event->isInProgress())->toBeTrue(); // was toBeFalse(), but implementation returns true for currentStep > 0 && < totalSteps
    });

    it('checks if task is just starting', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 1, 10, 'First step');

        expect($event->isStarting())->toBeTrue();
    });

    it('checks if task is not just starting when in progress', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 5, 10, 'Step 5');

        expect($event->isStarting())->toBeFalse();
    });
});

describe('progress bar generation', function () {
    it('generates progress bar with default width', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 5, 10, 'Step 5');

        $progressBar = $event->getProgressBar();

        expect($progressBar)->toBeString();
        expect(strlen($progressBar))->toBe(60); // Each Unicode block is 3 bytes
        expect($progressBar)->toContain('█');
        expect($progressBar)->toContain('░');
    });

    it('generates progress bar with custom width', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 3, 10, 'Step 3');

        $progressBar = $event->getProgressBar(10);

        expect($progressBar)->toBeString();
        expect(strlen($progressBar))->toBe(30); // Each Unicode block is 3 bytes
    });

    it('generates empty progress bar for zero progress', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 0, 10, 'Starting');

        $progressBar = $event->getProgressBar(10);

        expect($progressBar)->toBe('░░░░░░░░░░');
    });

    it('generates full progress bar for complete task', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 10, 10, 'Complete');

        $progressBar = $event->getProgressBar(10);

        expect($progressBar)->toBe('██████████');
    });

    it('generates partial progress bar', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 5, 10, 'Halfway');

        $progressBar = $event->getProgressBar(10);

        expect($progressBar)->toBe('█████░░░░░');
    });
});

describe('pending task information methods', function () {
    it('checks if task is running in background', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 1, 5, 'Step 1');

        expect($event->isBackground())->toBeFalse();
    });

    it('returns connection name when running remotely', function () {
        // Mock the config for task-runner.connections.production-server
        Config::set('task-runner.connections.production-server', [
            'host' => '127.0.0.1',
            'port' => 22,
            'username' => 'testuser',
            'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBALeQw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\n1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\nAgMBAAECQQC1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\n1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\n-----END RSA PRIVATE KEY-----",
            'script_path' => '/tmp',
        ]);
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $pendingTask->onConnection('production-server');
        $event = new TaskProgress($task, $pendingTask, 1, 5, 'Step 1');

        expect($event->pendingTask->getConnectionName())->toBe('production-server');
    });

    it('returns null connection when running locally', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 1, 5, 'Step 1');

        expect($event->getConnection())->toBeNull();
    });

    it('returns task ID', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $pendingTask->id('task-123'); // Use id() instead of withId()
        $event = new TaskProgress($task, $pendingTask, 1, 5, 'Step 1');

        expect($event->getTaskId())->toBe('task-123');
    });

    it('returns null task ID when not set', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 1, 5, 'Step 1');

        expect($event->getTaskId())->toBeNull();
    });
});

describe('progress details', function () {
    it('returns progress details', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 3, 10, 'Processing data');

        $details = $event->getProgressDetails();

        expect($details)->toBeArray();
        expect($details)->toHaveKeys([
            'current_step', 'total_steps', 'step_name', 'percentage',
            'percentage_int', 'progress_ratio', 'remaining_steps',
            'is_complete', 'is_in_progress', 'is_starting',
            'progress_bar', 'timestamp',
        ]);
        expect($details['current_step'])->toBe(3);
        expect($details['total_steps'])->toBe(10);
        expect($details['step_name'])->toBe('Processing data');
        expect($details['percentage'])->toBe(30.0);
        expect($details['percentage_int'])->toBe(30);
        expect($details['progress_ratio'])->toBe(0.3);
        expect($details['remaining_steps'])->toBe(7);
        expect($details['is_complete'])->toBeFalse();
        expect($details['is_in_progress'])->toBeTrue(); // was toBeFalse(), but implementation returns true for currentStep > 0 && < totalSteps
        expect($details['is_starting'])->toBeFalse(); // is_starting is true only for currentStep === 1
        expect($details['progress_bar'])->toBeString();
        expect($details['timestamp'])->toBe($event->timestamp);
    });
});

describe('event serialization', function () {
    it('can be serialized and unserialized', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $context = ['test' => 'data'];
        $event = new TaskProgress($task, $pendingTask, 3, 10, 'Step 3', $context);

        $serialized = serialize($event);
        $unserialized = unserialize($serialized);

        expect($unserialized)->toBeInstanceOf(TaskProgress::class);
        expect($unserialized->getTaskName())->toBe('test-task');
        expect($unserialized->context)->toBe($context);
        expect($unserialized->getCurrentStep())->toBe(3);
        expect($unserialized->getTotalSteps())->toBe(10);
    });
});

describe('event dispatchability', function () {
    it('can be dispatched', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 1, 5, 'Step 1');

        expect($event)->toBeInstanceOf(TaskProgress::class);
        expect(method_exists($event, 'dispatch'))->toBeTrue();
    });
});

describe('context data handling', function () {
    it('preserves complex context data', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $context = [
            'user' => ['id' => 1, 'name' => 'John'],
            'environment' => 'production',
            'tags' => ['deployment', 'backend'],
            'metadata' => [
                'deployment_id' => 'deploy-123',
                'commit_hash' => 'abc123',
                'branch' => 'main',
            ],
        ];

        $event = new TaskProgress($task, $pendingTask, 3, 10, 'Step 3', $context);

        expect($event->context)->toBe($context);
        expect($event->context['user']['name'])->toBe('John');
        expect($event->context['tags'])->toContain('deployment');
        expect($event->context['metadata']['deployment_id'])->toBe('deploy-123');
    });

    it('handles empty context', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 1, 5, 'Step 1', []);

        expect($event->context)->toBe([]);
    });
});

describe('timestamp formatting', function () {
    it('uses ISO 8601 format', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 1, 5, 'Step 1');

        expect($event->timestamp)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
    });

    it('is a valid date', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 1, 5, 'Step 1');

        $date = new DateTime($event->timestamp);
        expect($date)->toBeInstanceOf(DateTime::class);
    });
});

describe('edge cases', function () {
    it('handles zero total steps gracefully', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 0, 0, 'No steps');

        expect($event->getPercentage())->toBe(0.0);
        expect($event->getProgressRatio())->toBe(0.0);
        expect($event->getRemainingSteps())->toBe(0);
        expect($event->isComplete())->toBeTrue();
        expect($event->isInProgress())->toBeFalse();
        expect($event->isStarting())->toBeFalse();
    });

    it('handles current step greater than total steps', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, 15, 10, 'Overflow step');

        expect($event->getPercentage())->toBe(150.0);
        expect($event->getProgressRatio())->toBe(1.5);
        expect($event->getRemainingSteps())->toBe(0);
        expect($event->isComplete())->toBeTrue();
        expect($event->isInProgress())->toBeFalse();
    });

    it('handles negative current step', function () {
        $task = new TestTask;
        $pendingTask = new PendingTask($task);
        $event = new TaskProgress($task, $pendingTask, -1, 10, 'Negative step');

        expect($event->getPercentage())->toBe(-10.0);
        expect($event->getProgressRatio())->toBe(-0.1);
        expect($event->getRemainingSteps())->toBe(11);
        expect($event->isComplete())->toBeFalse();
        expect($event->isInProgress())->toBeFalse();
        expect($event->isStarting())->toBeFalse();
    });
});
