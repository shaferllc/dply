<?php

use App\Http\Controllers\Api\CloudDeployController;
use App\Http\Controllers\Api\CloudDeploymentController;
use App\Http\Controllers\Api\CloudProjectController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:60,1', 'cloud.token'])->group(function (): void {
    Route::get('/cloud/projects', [CloudProjectController::class, 'index'])->name('cloud.projects.index');
    Route::post('/cloud/projects', [CloudProjectController::class, 'store'])->name('cloud.projects.store');
    Route::get('/cloud/projects/{project}', [CloudProjectController::class, 'show'])->name('cloud.projects.show');
    Route::patch('/cloud/projects/{project}', [CloudProjectController::class, 'update'])->name('cloud.projects.update');
    Route::get('/cloud/deployments', [CloudDeploymentController::class, 'index'])->name('cloud.deployments.index');
    Route::get('/cloud/deployments/{deployment}', [CloudDeploymentController::class, 'show'])->name('cloud.deployments.show');
    Route::post('/cloud/deploy', CloudDeployController::class)->name('cloud.deploy');
});
