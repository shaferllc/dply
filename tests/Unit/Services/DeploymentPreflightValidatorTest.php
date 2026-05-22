<?php

namespace Tests\Unit\Services\DeploymentPreflightValidatorTest;

use App\Enums\SiteType;
use App\Models\Site;
use App\Services\Deploy\DeploymentPreflightValidator;

test('it flags missing repository for container targets', function () {
    $site = new Site([
        'name' => 'Container Site',
        'slug' => 'container-site',
        'type' => SiteType::Php,
        'meta' => [
            'runtime_profile' => 'docker_web',
            'runtime_target' => [
                'family' => 'docker',
                'mode' => 'docker',
                'platform' => 'byo',
                'provider' => 'byo',
            ],
            'docker_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                ],
            ],
        ],
    ]);

    $result = app(DeploymentPreflightValidator::class)->validate($site);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toContain('A repository URL is required for this runtime target.');
    expect($result['errors'])->toContain('Laravel deployments require APP_KEY before launch.');
});
