<?php

use App\Http\Controllers\Api\WordpressDeployController;
use App\Http\Controllers\Api\WordpressDeploymentController;
use App\Http\Controllers\Api\WordpressProjectController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:60,1', 'wordpress.token'])->group(function (): void {
    Route::get('/wordpress/projects', [WordpressProjectController::class, 'index'])->name('wordpress.projects.index');
    Route::post('/wordpress/projects', [WordpressProjectController::class, 'store'])->name('wordpress.projects.store');
    Route::get('/wordpress/projects/{project}', [WordpressProjectController::class, 'show'])->name('wordpress.projects.show');
    Route::patch('/wordpress/projects/{project}', [WordpressProjectController::class, 'update'])->name('wordpress.projects.update');
    Route::get('/wordpress/deployments', [WordpressDeploymentController::class, 'index'])->name('wordpress.deployments.index');
    Route::get('/wordpress/deployments/{deployment}', [WordpressDeploymentController::class, 'show'])->name('wordpress.deployments.show');
    Route::post('/wordpress/deploy', WordpressDeployController::class)->name('wordpress.deploy');
});
