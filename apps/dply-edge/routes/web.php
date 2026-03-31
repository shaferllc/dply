<?php

use App\Contracts\DeployEngine;
use App\Services\Deploy\DeployEngineResolver;
use App\Services\Deploy\EdgeDeployContext;
use Dply\Core\Security\WebhookSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $database = true;
    } catch (Throwable) {
        $database = false;
    }

    $ok = $database;

    return response()->json([
        'app' => 'dply-edge',
        'ok' => $ok,
        'checks' => [
            'database' => $database,
        ],
    ], $ok ? 200 : 503);
});

/** Monorepo spike: dply-core + {@see DeployEngine} stub (gated by EDGE_INTERNAL_SPIKE). */
Route::get('/internal/spike', function (DeployEngineResolver $deployEngineResolver) {
    $result = $deployEngineResolver->default()->run(new EdgeDeployContext(
        applicationName: 'spike-app',
        framework: 'next',
        gitRef: 'main',
    ));
    $deploy = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

    return response()->json([
        'app' => 'dply-edge',
        'dply_core' => [
            'webhook_signature_class' => WebhookSignature::class,
        ],
        'deploy' => $deploy,
        'engine' => [
            'sha' => $result['sha'],
        ],
    ]);
})->middleware('edge.internal');
