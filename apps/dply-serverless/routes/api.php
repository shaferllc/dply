<?php

use App\Http\Controllers\Api\ServerlessDeployController;
use App\Http\Controllers\Api\ServerlessDeploymentController;
use App\Http\Controllers\Api\ServerlessProjectController;
use App\Http\Controllers\Webhooks\ServerlessDeployWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/serverless/deploy', ServerlessDeployWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhooks.serverless.deploy');

Route::middleware(['throttle:30,1', 'serverless.token'])->group(function (): void {
    Route::get('/serverless/projects', [ServerlessProjectController::class, 'index'])->name('serverless.projects.index');
    Route::post('/serverless/projects', [ServerlessProjectController::class, 'store'])->name('serverless.projects.store');
    Route::get('/serverless/projects/{project}', [ServerlessProjectController::class, 'show'])->name('serverless.projects.show');
    Route::patch('/serverless/projects/{project}', [ServerlessProjectController::class, 'update'])->name('serverless.projects.update');
    Route::get('/serverless/deployments', [ServerlessDeploymentController::class, 'index'])->name('serverless.deployments.index');
    Route::get('/serverless/deployments/{deployment}', [ServerlessDeploymentController::class, 'show'])->name('serverless.deployments.show');
    Route::post('/serverless/deploy', ServerlessDeployController::class)->name('serverless.deploy');
});
