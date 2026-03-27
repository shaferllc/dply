<?php

use App\Contracts\DeployEngine;
use App\Services\Deploy\CloudDeployContext;
use App\Services\Deploy\DeployEngineResolver;
use Dply\Core\Security\WebhookSignature;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/** Monorepo spike: dply-core + {@see DeployEngine} stub (gated by CLOUD_INTERNAL_SPIKE). */
Route::get('/internal/spike', function (DeployEngineResolver $deployEngineResolver) {
    $result = $deployEngineResolver->default()->run(new CloudDeployContext(
        applicationName: 'spike-app',
        stack: 'php',
        gitRef: 'main',
    ));
    $deploy = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

    return response()->json([
        'app' => 'dply-cloud',
        'dply_core' => [
            'webhook_signature_class' => WebhookSignature::class,
        ],
        'deploy' => $deploy,
        'engine' => [
            'sha' => $result['sha'],
        ],
    ]);
})->middleware('cloud.internal');
