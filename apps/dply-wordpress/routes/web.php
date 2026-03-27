<?php

use App\Contracts\DeployEngine;
use App\Services\Deploy\DeployEngineResolver;
use App\Services\Deploy\WordpressDeployContext;
use Dply\Core\Security\WebhookSignature;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/** Monorepo spike: dply-core + {@see DeployEngine} stub (gated by WORDPRESS_INTERNAL_SPIKE). */
Route::get('/internal/spike', function (DeployEngineResolver $deployEngineResolver) {
    $result = $deployEngineResolver->default()->run(new WordpressDeployContext(
        applicationName: 'spike-app',
        phpVersion: '8.3',
        gitRef: 'main',
    ));
    $deploy = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

    return response()->json([
        'app' => 'dply-wordpress',
        'dply_core' => [
            'webhook_signature_class' => WebhookSignature::class,
        ],
        'deploy' => $deploy,
        'engine' => [
            'sha' => $result['sha'],
        ],
    ]);
})->middleware('wordpress.internal');
