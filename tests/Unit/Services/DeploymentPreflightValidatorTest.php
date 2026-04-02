<?php

namespace Tests\Unit\Services;

use App\Enums\SiteType;
use App\Models\Site;
use App\Services\Deploy\DeploymentPreflightValidator;
use Tests\TestCase;

class DeploymentPreflightValidatorTest extends TestCase
{
    public function test_it_flags_missing_repository_for_container_targets(): void
    {
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

        $this->assertFalse($result['ok']);
        $this->assertContains('A repository URL is required for this runtime target.', $result['errors']);
        $this->assertContains('Laravel deployments require APP_KEY before launch.', $result['errors']);
    }
}
