<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers\DayTwoAptToleranceTest;

use App\Models\ServerCacheService;
use App\Services\Servers\CaddyModulesManager;
use App\Support\Servers\AptSourceRepairScript;
use App\Support\Servers\CacheServiceInstallScripts;
use App\Support\Servers\DatabaseEngineInstallScripts;
use App\Support\Servers\HttpCacheDaemonInstallScripts;

/**
 * Day-two install scripts run under `set -e` with no provision preamble, so a
 * bare `apt-get update` lets one unverifiable third-party repo abort an
 * unrelated action — the failure that killed provisioning at the mise step.
 *
 * Every script here must call dply_apt_update AND carry its definition, since
 * calling an undefined function under `set -e` is a worse failure than the one
 * being fixed.
 */
function assertTolerant(string $script, string $label): void
{
    // PHPUnit asserts, not expect(): Pest's toContain() reads a second
    // argument as another needle rather than as a failure message.
    test()->assertStringContainsString(
        'dply_apt_update()',
        $script,
        "{$label}: dply_apt_update is called but never defined",
    );

    $bare = array_values(array_filter(
        preg_split('/\R/', $script) ?: [],
        fn (string $line) => preg_match('/(^\s*|[;&|]\s*|\|\|\s*|&&\s*)apt-get update/', $line) === 1
            && ! str_contains($line, 'dply_apt_update')
            && ! str_contains($line, 'log=$(apt-get update'),
    ));

    test()->assertSame([], $bare, "{$label}: bare apt-get update\n".implode("\n", $bare));
}

it('makes every database engine install tolerant', function () {
    foreach (DatabaseEngineInstallScripts::supportedEngines() as $engine) {
        assertTolerant(DatabaseEngineInstallScripts::installScript($engine), "database/{$engine}");
    }
});

it('makes every cache engine install tolerant', function () {
    foreach (['redis', 'valkey', 'keydb', 'dragonfly', 'memcached'] as $engine) {
        assertTolerant(CacheServiceInstallScripts::installScript($engine), "cache/{$engine}");
    }
});

it('makes the varnish install tolerant', function () {
    $row = new ServerCacheService(['engine' => 'varnish', 'name' => 'default']);

    assertTolerant(HttpCacheDaemonInstallScripts::installScriptForRow($row), 'http-cache/varnish');
});

it('makes the caddy module scripts tolerant', function () {
    $manager = app(CaddyModulesManager::class);

    assertTolerant($manager->restorePackageScript(), 'caddy/restore-package');
});

it('emits valid bash for the tolerant helper itself', function () {
    $path = tempnam(sys_get_temp_dir(), 'dply-tolerant-').'.sh';
    file_put_contents($path, AptSourceRepairScript::withTolerantApt("dply_apt_update\napt-get install -y curl\n"));

    exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exit);
    unlink($path);

    expect($exit)->toBe(0, implode("\n", $output));
});

it('keeps returning 0 so one broken repo cannot abort the caller', function () {
    // The contract that matters: these scripts run under `set -e`, so a
    // non-zero return here aborts the whole action for an unrelated repo.
    $dir = sys_get_temp_dir().'/dply-tol-'.bin2hex(random_bytes(6));
    mkdir($dir.'/bin', 0o755, true);
    mkdir($dir.'/etc/apt/sources.list.d', 0o755, true);

    file_put_contents($dir.'/bin/apt-get', "#!/bin/bash\necho 'E: The repository is not signed.'\nexit 100\n");
    chmod($dir.'/bin/apt-get', 0o755);

    file_put_contents($dir.'/run.sh', "set -euo pipefail\n"
        .AptSourceRepairScript::tolerantUpdateFunction()
        ."\ndply_apt_update\necho SURVIVED\n");

    exec(sprintf('PATH=%s:$PATH DPLY_APT_ROOT=%s bash %s 2>&1',
        escapeshellarg($dir.'/bin'), escapeshellarg($dir), escapeshellarg($dir.'/run.sh')), $output, $exit);

    expect($exit)->toBe(0)
        ->and(implode("\n", $output))->toContain('SURVIVED');
});
