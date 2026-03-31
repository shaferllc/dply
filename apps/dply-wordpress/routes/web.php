<?php

use App\Services\Deploy\DeployEngineResolver;
use App\Services\Deploy\WordpressDeployContext;
use Dply\Core\Security\WebhookSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

/** Production-safe liveness: database connectivity (Laravel also registers /up). */
Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $database = true;
    } catch (Throwable) {
        $database = false;
    }

    $ok = $database;

    return response()->json([
        'app' => 'dply-wordpress',
        'ok' => $ok,
        'checks' => [
            'database' => $database,
        ],
    ], $ok ? 200 : 503);
});

/** Dev/CI: dply-core + {@see DeployEngine} (same engine as deploy jobs; gated by WORDPRESS_INTERNAL_SPIKE). */
Route::get('/internal/spike', function (DeployEngineResolver $deployEngineResolver) {
    $result = $deployEngineResolver->default()->run(new WordpressDeployContext(
        applicationName: 'spike-app',
        phpVersion: '8.3',
        gitRef: 'main',
        trigger: 'internal_spike',
        providerConfig: [
            'project' => [
                'id' => 0,
                'slug' => 'spike',
                'settings' => [
                    'runtime' => 'hosted',
                    'environment_id' => 'env-spike',
                ],
            ],
        ],
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
