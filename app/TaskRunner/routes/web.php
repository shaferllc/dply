<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

Route::middleware(['web', 'auth'])->group(function () {
    Route::prefix('tasks')->group(function () {
        Route::get('/', function () {
            return view('task-runner::task-dashboard');
        })->name('tasks.dashboard');

        Route::get('/execute', function () {
            return view('task-runner::task-execute');
        })->name('tasks.execute');

        Route::get('/list', function () {
            return view('task-runner::task-list');
        })->name('tasks.list');

        Route::get('/monitor', function () {
            return view('task-runner::monitor', ['showAllTasks' => true]);
        })->name('tasks.monitor');

        Route::get('/name/{taskName}', function (string $taskName) {
            return view('task-runner::monitor', ['taskName' => $taskName]);
        })->name('tasks.monitor.name');

        Route::get('/id/{taskId}', function (string $taskId) {
            return view('task-runner::monitor', ['taskId' => $taskId]);
        })->name('tasks.monitor.id');
    });
});

Route::middleware(['web', 'signed'])->prefix('webhook')->name('webhook.')->group(function () {
    $finalizeTask = function (Task $task, TaskStatus $status, int $defaultExitCode, Request $request): void {
        $task->refresh();

        if (in_array($task->status, [TaskStatus::Finished, TaskStatus::Failed, TaskStatus::Timeout, TaskStatus::Cancelled], true)) {
            Log::info('Task webhook finalize skipped for terminal task', [
                'task_id' => $task->id,
                'current_status' => $task->status->value,
                'requested_status' => $status->value,
            ]);

            return;
        }

        $task->update([
            'status' => $status,
            'exit_code' => (int) $request->input('exit_code', $defaultExitCode),
            'completed_at' => now(),
        ]);

        Log::info('Task webhook finalized task', [
            'task_id' => $task->id,
            'status' => $status->value,
            'exit_code' => (int) $request->input('exit_code', $defaultExitCode),
        ]);
    };

    Route::post('/task/callback/{task}', function (Task $task, Request $request) use ($finalizeTask) {
        $task->handleCallback($request, CallbackType::Finished);
        $finalizeTask($task, TaskStatus::Finished, 0, $request);

        return response()->json(['status' => 'success']);
    })->name('task.callback');

    Route::post('/task/mark-as-finished/{task}', function (Task $task, Request $request) use ($finalizeTask) {
        Log::info('Task finish webhook received', [
            'task_id' => $task->id,
            'current_status' => $task->status->value,
            'exit_code' => (int) $request->input('exit_code', 0),
        ]);

        $task->handleCallback($request, CallbackType::Finished);
        $finalizeTask($task, TaskStatus::Finished, 0, $request);

        return response()->json(['status' => 'success']);
    })->name('task.mark-as-finished');

    Route::post('/task/mark-as-failed/{task}', function (Task $task, Request $request) use ($finalizeTask) {
        $task->handleCallback($request, CallbackType::Failed);
        $finalizeTask($task, TaskStatus::Failed, 1, $request);

        return response()->json(['status' => 'success']);
    })->name('task.mark-as-failed');

    Route::post('/task/mark-as-timed-out/{task}', function (Task $task, Request $request) use ($finalizeTask) {
        $task->handleCallback($request, CallbackType::Timeout);
        $finalizeTask($task, TaskStatus::Timeout, 124, $request);

        return response()->json(['status' => 'success']);
    })->name('task.mark-as-timed-out');

    Route::post('/task/update-output/{task}', function (Task $task, Request $request) {
        if (! $task->status->isActive()) {
            return response()->json(['status' => 'ignored']);
        }

        $output = $request->input('output', '');
        $scriptContent = $request->input('script_content', '');
        $appendNewline = $request->boolean('append_newline');

        $updateData = [];

        if (! empty($output)) {
            if ($appendNewline && ! str_ends_with($output, "\n")) {
                $output .= "\n";
            }

            $existingOutput = (string) ($task->output ?? '');
            $needsSeparator = $existingOutput !== ''
                && ! str_ends_with($existingOutput, "\n")
                && ! str_starts_with($output, "\n");

            $updateData['output'] = $existingOutput.($needsSeparator ? "\n" : '').$output;
        }

        if (! empty($scriptContent)) {
            $updateData['script_content'] = $scriptContent;
        }

        if (! empty($updateData)) {
            $task->update($updateData);
        }

        return response()->json(['status' => 'success']);
    })->name('task.update-output');
});
