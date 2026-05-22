<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Concerns;

use App\Livewire\Concerns\DetectsRepositoryRuntime;
use PHPUnit\Framework\TestCase;

class DetectsRepositoryRuntimeTest extends TestCase
{
    private function subject(): object
    {
        return new class
        {
            use DetectsRepositoryRuntime;

            protected function applyDetectedRuntimePrefills(): void {}

            /**
             * @param  array<string, mixed>  $detection
             * @return array<string, mixed>
             */
            public function exposeServerlessDetectionToArray(array $detection, string $url, string $branch): array
            {
                return $this->serverlessDetectionToArray($detection, $url, $branch);
            }
        };
    }

    public function test_normalize_to_clone_url_expands_owner_name_shorthand(): void
    {
        $this->assertSame(
            'https://github.com/acme/api.git',
            $this->subject()->normalizeToCloneUrl('acme/api'),
        );
    }

    public function test_normalize_to_clone_url_trims_surrounding_slashes(): void
    {
        $this->assertSame(
            'https://github.com/acme/api.git',
            $this->subject()->normalizeToCloneUrl('/acme/api/'),
        );
    }

    public function test_normalize_to_clone_url_passes_full_urls_through(): void
    {
        $subject = $this->subject();

        $this->assertSame(
            'https://github.com/acme/api.git',
            $subject->normalizeToCloneUrl('https://github.com/acme/api.git'),
        );
        $this->assertSame(
            'git@github.com:acme/api.git',
            $subject->normalizeToCloneUrl('git@github.com:acme/api.git'),
        );
    }

    public function test_normalize_to_clone_url_returns_empty_for_blank_input(): void
    {
        $this->assertSame('', $this->subject()->normalizeToCloneUrl('   '));
    }

    public function test_serverless_detection_collapses_unknown_framework_to_no_match(): void
    {
        $plan = $this->subject()->exposeServerlessDetectionToArray(
            ['framework' => 'unknown', 'runtime' => ''],
            'https://github.com/acme/api.git',
            'main',
        );

        $this->assertTrue($plan['no_match']);
        $this->assertSame('serverless', $plan['kind']);
        $this->assertArrayNotHasKey('runtime', $plan);
    }

    public function test_serverless_detection_maps_a_recognized_action(): void
    {
        $plan = $this->subject()->exposeServerlessDetectionToArray(
            [
                'framework' => 'raw',
                'deploy_kind' => 'raw',
                'runtime' => 'php:8.3',
                'entrypoint' => 'main',
                'build_command' => 'composer install',
                'confidence' => 'high',
                'reasons' => ['Detected a raw OpenWhisk php action.'],
                'warnings' => [],
            ],
            'https://github.com/acme/api.git',
            'main',
        );

        $this->assertSame('serverless', $plan['kind']);
        $this->assertSame('raw', $plan['framework']);
        $this->assertSame('php:8.3', $plan['runtime']);
        $this->assertSame('main', $plan['entrypoint']);
        $this->assertSame('composer install', $plan['build_command']);
        $this->assertSame('high', $plan['confidence']);
        $this->assertNull($plan['version']);
        $this->assertSame([], $plan['processes']);
        $this->assertArrayNotHasKey('no_match', $plan);
    }
}
