<?php

use App\Http\Controllers\Api\OperatorReadmeController;
use App\Http\Controllers\Api\OperatorSummaryController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\SiteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::middleware('fleet.operator')->group(function (): void {
        Route::get('/operator/summary', [OperatorSummaryController::class, 'show']);
        Route::get('/operator/readme', [OperatorReadmeController::class, 'show']);
    });

    $apiAbilities = config('api_token_permissions.http_route_abilities', []);

    Route::middleware(['auth.api', 'throttle:api'])->group(function () use ($apiAbilities): void {
        Route::get('/servers', [ServerController::class, 'index'])->middleware('ability:'.$apiAbilities['servers.index']);
        Route::post('/servers/{server}/deploy', [ServerController::class, 'deploy'])->middleware('ability:'.$apiAbilities['servers.deploy']);
        Route::post('/servers/{server}/run-command', [ServerController::class, 'runCommand'])->middleware('ability:'.$apiAbilities['servers.run_command']);

        Route::get('/sites', [SiteController::class, 'index'])->middleware('ability:'.$apiAbilities['sites.index']);
        Route::post('/sites/{site}/deploy', [SiteController::class, 'deploy'])->middleware('ability:'.$apiAbilities['sites.deploy']);
        Route::get('/sites/{site}/deployments', [SiteController::class, 'deployments'])->middleware('ability:'.$apiAbilities['sites.deployments']);
        Route::get('/sites/{site}/deployments/{deployment}', [SiteController::class, 'showDeployment'])->middleware('ability:'.$apiAbilities['sites.deployment_show']);
    });
});
