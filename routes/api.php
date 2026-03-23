<?php

use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\SiteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth.api', 'throttle:api'])->group(function () {
    Route::get('/servers', [ServerController::class, 'index'])->middleware('ability:servers.read');
    Route::post('/servers/{server}/deploy', [ServerController::class, 'deploy'])->middleware('ability:servers.deploy');
    Route::post('/servers/{server}/run-command', [ServerController::class, 'runCommand'])->middleware('ability:commands.run');

    Route::get('/sites', [SiteController::class, 'index'])->middleware('ability:sites.read');
    Route::post('/sites/{site}/deploy', [SiteController::class, 'deploy'])->middleware('ability:sites.deploy');
    Route::get('/sites/{site}/deployments', [SiteController::class, 'deployments'])->middleware('ability:sites.read');
    Route::get('/sites/{site}/deployments/{deployment}', [SiteController::class, 'showDeployment'])->middleware('ability:sites.read');
});
