<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers\AptSourceRepairScriptTest;

use App\Support\Servers\AptSourceRepairScript;

/**
 * The repair script deletes files on a live host, so it runs as real bash
 * against a fake apt-get and a temp sources tree rather than being asserted on
 * as a string.
 *
 * @param  array<string, string>  $sources  basename => contents
 * @return array{root: string, exit: int, output: string}
 */
function runRepair(string $aptOutput, array $sources, bool $dryRun = false, string $breaksApt = 'dply-mysql.list'): array
{
    $root = sys_get_temp_dir().'/dply-repair-'.bin2hex(random_bytes(6));
    $dir = $root.'/etc/apt/sources.list.d';
    $bin = $root.'/bin';
    mkdir($dir, 0o755, true);
    mkdir($bin, 0o755, true);

    foreach ($sources as $name => $contents) {
        file_put_contents($dir.'/'.$name, $contents);
    }

    // A fake apt-get that keeps failing while the named source file is present
    // and succeeds once it is gone. The second update is what proves the repair
    // actually worked, and naming the file (rather than inferring from the
    // directory) is what lets a distro-mirror case stay broken after the prune
    // correctly declines to touch it.
    file_put_contents($root.'/apt-output', $aptOutput);
    $breaker = $breaksApt === '' ? '' : $dir.'/'.$breaksApt;
    file_put_contents($bin.'/apt-get', <<<SH
#!/bin/bash
if [ -n "{$breaker}" ] && [ -f "{$breaker}" ]; then
  cat "{$root}/apt-output"
  exit 100
fi
echo "Hit:1 http://mirrors.example/ubuntu noble InRelease"
exit 0
SH);
    chmod($bin.'/apt-get', 0o755);

    $script = $root.'/repair.sh';
    file_put_contents($script, AptSourceRepairScript::repairScript($dryRun));

    $cmd = sprintf(
        'PATH=%s:$PATH DPLY_APT_ROOT=%s bash %s 2>&1',
        escapeshellarg($bin),
        escapeshellarg($root),
        escapeshellarg($script),
    );

    exec($cmd, $output, $exit);

    return ['root' => $dir, 'exit' => $exit, 'output' => implode("\n", $output)];
}

const EXPIRED_KEY_OUTPUT = <<<'LOG'
Err:5 https://repo.mysql.com/apt/ubuntu noble InRelease
  The following signatures were invalid: EXPKEYSIG B7B3B788A8D3785C MySQL Release Engineering
E: The repository 'https://repo.mysql.com/apt/ubuntu noble InRelease' is not signed.
LOG;

const MYSQL_LIST = "deb [signed-by=/usr/share/keyrings/dply-mysql.gpg] https://repo.mysql.com/apt/ubuntu noble mysql-8.4-lts\n";

const UBUNTU_SOURCES = "Types: deb\nURIs: http://mirrors.digitalocean.com/ubuntu\nSuites: noble\n";

it('removes the dead source and confirms apt is clean afterwards', function () {
    $r = runRepair(EXPIRED_KEY_OUTPUT, ['dply-mysql.list' => MYSQL_LIST, 'ubuntu.sources' => UBUNTU_SOURCES]);

    expect($r['output'])->toContain('RESULT: repaired')
        ->and($r['exit'])->toBe(0)
        ->and(file_exists($r['root'].'/dply-mysql.list'))->toBeFalse()
        ->and(file_exists($r['root'].'/ubuntu.sources'))->toBeTrue();
});

it('reports without deleting under --dry-run', function () {
    $r = runRepair(EXPIRED_KEY_OUTPUT, ['dply-mysql.list' => MYSQL_LIST], dryRun: true);

    expect($r['output'])->toContain('WOULD REMOVE')
        ->and($r['output'])->toContain('RESULT: would-repair')
        ->and($r['exit'])->toBe(0)
        // The whole point of the dry run: the file is still there.
        ->and(file_exists($r['root'].'/dply-mysql.list'))->toBeTrue();
});

it('is a no-op on a healthy host', function () {
    $r = runRepair('', [], breaksApt: '');

    expect($r['output'])->toContain('RESULT: ok')
        ->and($r['output'])->toContain('nothing to repair')
        ->and($r['exit'])->toBe(0);
});

it('leaves sources alone when the failure is not a signature failure', function () {
    $log = <<<'LOG'
Err:5 https://repo.mysql.com/apt/ubuntu noble InRelease
  Could not connect to repo.mysql.com:443, connection timed out
E: Failed to fetch https://repo.mysql.com/apt/ubuntu/dists/noble/InRelease
LOG;

    $r = runRepair($log, ['dply-mysql.list' => MYSQL_LIST]);

    // A mirror being down is transient; deleting the source would make a
    // temporary outage permanent.
    expect($r['output'])->toContain('RESULT: no-action')
        ->and($r['exit'])->toBe(1)
        ->and(file_exists($r['root'].'/dply-mysql.list'))->toBeTrue();
});

it('never removes a distro mirror, however it fails', function () {
    $log = <<<'LOG'
Err:1 http://mirrors.digitalocean.com/ubuntu noble InRelease
  The following signatures were invalid: EXPKEYSIG 871920D1991BC93C
E: The repository 'http://mirrors.digitalocean.com/ubuntu noble InRelease' is not signed.
LOG;

    $r = runRepair($log, ['ubuntu.sources' => UBUNTU_SOURCES], breaksApt: 'ubuntu.sources');

    expect(file_exists($r['root'].'/ubuntu.sources'))->toBeTrue()
        ->and($r['output'])->toContain('needs a human')
        ->and($r['output'])->toContain('RESULT: no-action');
});

it('is valid bash in both modes', function () {
    foreach ([true, false] as $dryRun) {
        $path = tempnam(sys_get_temp_dir(), 'dply-repair-').'.sh';
        file_put_contents($path, AptSourceRepairScript::repairScript($dryRun));
        exec('bash -n '.escapeshellarg($path).' 2>&1', $out, $exit);
        unlink($path);

        expect($exit)->toBe(0, implode("\n", $out));
    }
});

it('shares one prune implementation with the provision preamble', function () {
    // Two copies of this policy would drift, and the drift would only show up
    // on a live host.
    $preamble = file_get_contents(base_path('app/Jobs/Concerns/BuildsProvisionScriptPreamble.php'));

    expect($preamble)->toContain('AptSourceRepairScript::pruneFunction()')
        ->and($preamble)->not->toContain('dply_prune_unverifiable_apt_sources() {');
});
