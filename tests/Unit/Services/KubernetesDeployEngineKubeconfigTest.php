<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Deploy\KubernetesDeployEngine;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Tests the kubeconfig materialisation path that wires the poller's
 * meta.kubernetes.kubeconfig YAML into kubectl. Direct unit test on the
 * private materialiseKubeconfig() method — the integration path is harder
 * to exercise without spinning up an actual kubectl process.
 */
final class KubernetesDeployEngineKubeconfigTest extends TestCase
{
    public function test_materialise_kubeconfig_writes_yaml_to_a_secure_temp_file(): void
    {
        $engine = app(KubernetesDeployEngine::class);
        $reflection = new ReflectionMethod($engine, 'materialiseKubeconfig');
        $reflection->setAccessible(true);

        $yaml = "apiVersion: v1\nkind: Config\nclusters: []\n";
        $path = $reflection->invoke($engine, $yaml);

        try {
            $this->assertFileExists($path);
            // macOS sometimes resolves sys_get_temp_dir() to /var/... but
            // realpath returns /private/var/... — compare on realpath to be
            // platform-agnostic.
            $this->assertStringStartsWith(realpath(sys_get_temp_dir()), realpath($path));
            $this->assertSame($yaml, file_get_contents($path));

            // Permissions: 0600 = owner read/write only (no group/world access).
            // tempnam + the explicit chmod inside materialiseKubeconfig should
            // both deliver this — if a future change breaks it, we'd be writing
            // bearer creds to a world-readable file, which is bad.
            $perms = fileperms($path) & 0777;
            $this->assertSame(0600, $perms, sprintf(
                'kubeconfig temp file must be 0600; got 0%o',
                $perms,
            ));
        } finally {
            @unlink($path);
        }
    }
}
