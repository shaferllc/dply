<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::group([], function () {
    // Task listing and management
    Route::get('/tasks', [TaskController::class, 'index'])->name('api.v1.tasks.index');
    Route::get('/tasks/stats', [TaskController::class, 'stats'])->name('api.v1.tasks.stats');
    Route::get('/tasks/search', [TaskController::class, 'search'])->name('api.v1.tasks.search');
    Route::get('/tasks/status/{status}', [TaskController::class, 'byStatus'])->name('api.v1.tasks.by-status');

    // Task execution
    Route::post('/tasks/run', [TaskController::class, 'run'])->name('api.v1.tasks.run');
    Route::post('/tasks/run/parallel', [TaskController::class, 'runParallel'])->name('api.v1.tasks.run-parallel');
    Route::post('/tasks/run/chain', [TaskController::class, 'runChain'])->name('api.v1.tasks.run-chain');

    // Individual task operations
    Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('api.v1.tasks.show');
    Route::get('/tasks/{task}/stream', [TaskController::class, 'stream'])->name('api.v1.tasks.stream');
    Route::post('/tasks/{task}/cancel', [TaskController::class, 'cancel'])->name('api.v1.tasks.cancel');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('api.v1.tasks.destroy');

    // Bulk operations
    Route::post('/tasks/clear-completed', [TaskController::class, 'clearCompleted'])->name('api.v1.tasks.clear-completed');
});
