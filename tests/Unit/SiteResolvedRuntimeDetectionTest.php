<?php

namespace Tests\Unit;

use App\Models\Site;
use Tests\TestCase;

class SiteResolvedRuntimeDetectionTest extends TestCase
{
    public function test_resolved_detection_prefers_docker_meta_over_kubernetes_and_serverless(): void
    {
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

        $this->assertNotNull($resolved);
        $this->assertSame('docker', $resolved['source']);
        $this->assertSame('laravel', $resolved['framework']);
        $this->assertSame('php', $resolved['language']);
        $this->assertSame('high', $resolved['confidence']);
    }

    public function test_resolved_detection_includes_laravel_octane_when_present_in_blob(): void
    {
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

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved['laravel_octane']);
    }

    public function test_resolved_detection_falls_through_to_kubernetes_when_docker_empty(): void
    {
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

        $this->assertNotNull($resolved);
        $this->assertSame('kubernetes', $resolved['source']);
        $this->assertSame('nuxt', $resolved['framework']);
    }

    public function test_resolved_detection_uses_serverless_detected_runtime_shape(): void
    {
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

        $this->assertNotNull($resolved);
        $this->assertSame('serverless', $resolved['source']);
        $this->assertSame(['Test warning'], $resolved['warnings']);
    }

    public function test_resolved_detection_returns_null_when_only_unknown_framework_and_language(): void
    {
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

        $this->assertNull($site->resolvedRuntimeAppDetection());
    }

    public function test_runtime_profile_label_maps_known_profiles(): void
    {
        $site = new Site;

        $site->meta = ['runtime_profile' => 'vm_web'];
        $this->assertSame('BYO VM', $site->runtimeProfileLabel());

        $site->meta = ['runtime_profile' => 'docker_web'];
        $this->assertSame('Docker', $site->runtimeProfileLabel());

        $site->meta = ['runtime_profile' => 'kubernetes_web'];
        $this->assertSame('Kubernetes', $site->runtimeProfileLabel());

        $site->meta = ['runtime_profile' => 'digitalocean_functions_web'];
        $this->assertSame('DigitalOcean Functions', $site->runtimeProfileLabel());

        $site->meta = ['runtime_profile' => 'aws_lambda_bref_web'];
        $this->assertSame('AWS Lambda', $site->runtimeProfileLabel());
    }
}
