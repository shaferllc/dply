<?php


namespace Tests\Unit\SiteResolvedRuntimeDetectionTest;
use App\Models\Site;

test('resolved detection prefers docker meta over kubernetes and serverless', function () {
    $site = new Site([
        'meta' => [
            'docker_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                    'language' => 'php',
                    'confidence' => 'high',
                ],
            ],
            'kubernetes_runtime' => [
                'detected' => [
                    'framework' => 'nextjs',
                    'language' => 'node',
                ],
            ],
            'serverless' => [
                'detected_runtime' => [
                    'framework' => 'vite_static',
                    'language' => 'node',
                ],
            ],
        ],
    ]);

    $resolved = $site->resolvedRuntimeAppDetection();

    expect($resolved)->not->toBeNull();
    expect($resolved['source'])->toBe('docker');
    expect($resolved['framework'])->toBe('laravel');
    expect($resolved['language'])->toBe('php');
    expect($resolved['confidence'])->toBe('high');
});

test('resolved detection includes laravel octane when present in blob', function () {
    $site = new Site([
        'meta' => [
            'docker_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                    'language' => 'php',
                    'laravel_octane' => true,
                ],
            ],
        ],
    ]);

    $resolved = $site->resolvedRuntimeAppDetection();

    expect($resolved)->not->toBeNull();
    expect($resolved['laravel_octane'])->toBeTrue();
});

test('resolved detection falls through to kubernetes when docker empty', function () {
    $site = new Site([
        'meta' => [
            'docker_runtime' => ['detected' => []],
            'kubernetes_runtime' => [
                'detected' => [
                    'framework' => 'nuxt',
                    'language' => 'node',
                    'confidence' => 'medium',
                ],
            ],
        ],
    ]);

    $resolved = $site->resolvedRuntimeAppDetection();

    expect($resolved)->not->toBeNull();
    expect($resolved['source'])->toBe('kubernetes');
    expect($resolved['framework'])->toBe('nuxt');
});

test('resolved detection uses serverless detected runtime shape', function () {
    $site = new Site([
        'meta' => [
            'serverless' => [
                'detected_runtime' => [
                    'framework' => 'laravel',
                    'language' => 'php',
                    'confidence' => 'high',
                    'warnings' => ['Test warning'],
                ],
            ],
        ],
    ]);

    $resolved = $site->resolvedRuntimeAppDetection();

    expect($resolved)->not->toBeNull();
    expect($resolved['source'])->toBe('serverless');
    expect($resolved['warnings'])->toBe(['Test warning']);
});

test('resolved detection returns null when only unknown framework and language', function () {
    $site = new Site([
        'meta' => [
            'docker_runtime' => [
                'detected' => [
                    'framework' => 'unknown',
                    'language' => 'unknown',
                ],
            ],
        ],
    ]);

    expect($site->resolvedRuntimeAppDetection())->toBeNull();
});

test('runtime profile label maps known profiles', function () {
    $site = new Site;

    $site->meta = ['runtime_profile' => 'vm_web'];
    expect($site->runtimeProfileLabel())->toBe('BYO VM');

    $site->meta = ['runtime_profile' => 'docker_web'];
    expect($site->runtimeProfileLabel())->toBe('Docker');

    $site->meta = ['runtime_profile' => 'kubernetes_web'];
    expect($site->runtimeProfileLabel())->toBe('Kubernetes');

    $site->meta = ['runtime_profile' => 'digitalocean_functions_web'];
    expect($site->runtimeProfileLabel())->toBe('DigitalOcean Functions');

    $site->meta = ['runtime_profile' => 'aws_lambda_bref_web'];
    expect($site->runtimeProfileLabel())->toBe('AWS Lambda');
});
