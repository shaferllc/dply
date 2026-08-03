<?php

declare(strict_types=1);

use App\Livewire\Servers\Concerns\BuildsProvisionDiagnostics;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;

/**
 * stallState() is protected on the ProvisionJourney component; this harness
 * exposes just that method — it depends on nothing else from the component.
 */
function stallHarness(): object
{
    return new class
    {
        use BuildsProvisionDiagnostics;

        /** @param  list<array<string, mixed>>  $steps */
        public function state(?Task $task, array $steps = []): ?array
        {
            return $this->stallState($task, $steps);
        }
    };
}

/**
 * A running task that started $startedMinutesAgo ago and last wrote output
 * $quietSeconds ago.
 */
function runningTask(int $startedMinutesAgo, int $quietSeconds): Task
{
    $task = new Task;
    $task->forceFill([
        'status' => TaskStatus::Running,
        'started_at' => now()->subMinutes($startedMinutesAgo),
        'updated_at' => now()->subSeconds($quietSeconds),
    ]);

    return $task;
}

test('a long run that is still producing output is not flagged as stalled', function (): void {
    // The regression: past 8 minutes the warning used to latch on and never
    // clear, however much output was still arriving.
    $state = stallHarness()->state(runningTask(startedMinutesAgo: 20, quietSeconds: 2));

    expect($state['stalled'])->toBeFalse()
        ->and($state['warning'])->toBeNull();
});

test('a long run that has gone quiet is flagged as stalled', function (): void {
    $state = stallHarness()->state(runningTask(startedMinutesAgo: 20, quietSeconds: 45));

    expect($state['stalled'])->toBeTrue()
        ->and($state['warning'])->toContain('may be stalled');
});

test('a short run that has gone quiet for minutes is flagged as stalled', function (): void {
    $state = stallHarness()->state(runningTask(startedMinutesAgo: 4, quietSeconds: 200));

    expect($state['stalled'])->toBeTrue();
});

test('a short run with recent output is not flagged as stalled', function (): void {
    $state = stallHarness()->state(runningTask(startedMinutesAgo: 4, quietSeconds: 5));

    expect($state['stalled'])->toBeFalse()
        ->and($state['warning'])->toBeNull();
});

test('the warning clears once output resumes', function (): void {
    $harness = stallHarness();

    expect($harness->state(runningTask(startedMinutesAgo: 12, quietSeconds: 240))['warning'])->not->toBeNull();
    // Same run, one poll later, output has landed.
    expect($harness->state(runningTask(startedMinutesAgo: 12, quietSeconds: 1))['warning'])->toBeNull();
});

test('no stall state at all once the task is no longer active', function (): void {
    $task = new Task;
    $task->forceFill([
        'status' => TaskStatus::Finished,
        'started_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
    ]);

    expect(stallHarness()->state($task))->toBeNull()
        ->and(stallHarness()->state(null))->toBeNull();
});
