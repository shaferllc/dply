<?php

use App\Features\ServerlessFeature;
use App\Services\Deploy\DeployEngineResolver;
use App\Services\Deploy\ServerlessDeployContext;
use Dply\Core\Security\WebhookSignature;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/serverless', 'serverless.overview')
    ->middleware('pennant.feature:'.ServerlessFeature::PUBLIC_DASHBOARD);

/** Monorepo spike: dply-core + DeployEngine → stub provisioner (gated by Pennant; off in production unless env set). */
Route::get('/internal/spike', function (DeployEngineResolver $deployEngineResolver) {
    $result = $deployEngineResolver->default()->run(new ServerlessDeployContext(
        functionName: 'spike-fn',
        runtime: 'provided.al2023',
        artifactPath: '/dev/null',
    ));
    $provision = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

    return response()->json([
        'app' => 'dply-serverless',
        'dply_core' => [
            'webhook_signature_class' => WebhookSignature::class,
        ],
        'provision' => $provision,
        'engine' => [
            'sha' => $result['sha'],
        ],
    ]);
})->middleware('pennant.feature:'.ServerlessFeature::INTERNAL_SPIKE);
