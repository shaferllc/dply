<?php

use App\Http\Controllers\Api\EdgeDeployController;
use App\Http\Controllers\Api\EdgeDeploymentController;
use App\Http\Controllers\Api\EdgeProjectController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:60,1', 'edge.token'])->group(function (): void {
    Route::get('/edge/projects', [EdgeProjectController::class, 'index'])->name('edge.projects.index');
    Route::post('/edge/projects', [EdgeProjectController::class, 'store'])->name('edge.projects.store');
    Route::get('/edge/projects/{project}', [EdgeProjectController::class, 'show'])->name('edge.projects.show');
    Route::patch('/edge/projects/{project}', [EdgeProjectController::class, 'update'])->name('edge.projects.update');
    Route::get('/edge/deployments', [EdgeDeploymentController::class, 'index'])->name('edge.deployments.index');
    Route::get('/edge/deployments/{deployment}', [EdgeDeploymentController::class, 'show'])->name('edge.deployments.show');
    Route::post('/edge/deploy', EdgeDeployController::class)->name('edge.deploy');
});
