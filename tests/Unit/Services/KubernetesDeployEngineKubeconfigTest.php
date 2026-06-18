<?php

declare(strict_types=1);

namespace Tests\Unit\Services\KubernetesDeployEngineKubeconfigTest;

use App\Modules\Deploy\Services\KubernetesDeployEngine;
use ReflectionMethod;

test('materialise kubeconfig writes yaml to a secure temp file', function () {
    $engine = app(KubernetesDeployEngine::class);
    $reflection = new ReflectionMethod($engine, 'materialiseKubeconfig');
    $reflection->setAccessible(true);

    $yaml = "apiVersion: v1\nkind: Config\nclusters: []\n";
    $path = $reflection->invoke($engine, $yaml);

    try {
        expect($path)->toBeFile();
        // macOS sometimes resolves sys_get_temp_dir() to /var/... but
        // realpath returns /private/var/... — compare on realpath to be
        // platform-agnostic.
        expect(realpath($path))->toStartWith(realpath(sys_get_temp_dir()));
        expect(file_get_contents($path))->toBe($yaml);

        // Permissions: 0600 = owner read/write only (no group/world access).
        // tempnam + the explicit chmod inside materialiseKubeconfig should
        // both deliver this — if a future change breaks it, we'd be writing
        // bearer creds to a world-readable file, which is bad.
        $perms = fileperms($path) & 0777;
        expect($perms)->toBe(0600, sprintf(
            'kubeconfig temp file must be 0600; got 0%o',
            $perms,
        ));
    } finally {
        @unlink($path);
    }
});
