<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\ProvisionAptSourcePrunerTest;

use App\Jobs\Concerns\BuildsProvisionScriptPreamble;
use App\Models\ServerProvisionRun;

/**
 * The pruner deletes files on a live host, so it is exercised as bash rather
 * than asserted on as a string. Only the function is sourced — the preamble's
 * top-level lines write to /var/lib/dply.
 */
function prunerFunction(): string
{
    $host = new class
    {
        use BuildsProvisionScriptPreamble;

        public function render(): string
        {
            $run = new ServerProvisionRun;
            $run->id = '01TEST';

            return $this->provisionScriptPreamble('task', $run);
        }
    };

    preg_match('/dply_prune_unverifiable_apt_sources\(\) \{.*?\n\}\n/s', $host->render(), $m);

    expect($m[0] ?? null)->not->toBeNull('pruner function not found in the preamble');

    return $m[0];
}

/**
 * @param  array<string, string>  $sources  basename => contents
 * @return array{root: string, exit: int, output: string}
 */
function runPruner(string $aptLog, array $sources): array
{
    $root = sys_get_temp_dir().'/dply-apt-'.bin2hex(random_bytes(6));
    $dir = $root.'/etc/apt/sources.list.d';
    mkdir($dir, 0o755, true);
    foreach ($sources as $name => $contents) {
        file_put_contents($dir.'/'.$name, $contents);
    }

    $script = $root.'/pruner.sh';
    file_put_contents($script, "set -euo pipefail\n".prunerFunction());
    file_put_contents($root.'/apt.log', $aptLog);

    $cmd = sprintf(
        'bash -c %s 2>&1',
        escapeshellarg(sprintf(
            'source %s; DPLY_APT_ROOT=%s dply_prune_unverifiable_apt_sources "$(cat %s)"',
            escapeshellarg($script),
            escapeshellarg($root),
            escapeshellarg($root.'/apt.log'),
        )),
    );

    exec($cmd, $output, $exit);

    return ['root' => $dir, 'exit' => $exit, 'output' => implode("\n", $output)];
}

const MYSQL_LIST = "deb [signed-by=/usr/share/keyrings/dply-mysql.gpg] https://repo.mysql.com/apt/ubuntu noble mysql-8.4-lts\n";

const MISE_LIST = "deb [signed-by=/etc/apt/keyrings/mise-archive-keyring.gpg] https://mise.jdx.dev/deb stable main\n";

const UBUNTU_SOURCES = "Types: deb\nURIs: http://mirrors.digitalocean.com/ubuntu\nSuites: noble\n";

// The log from the droplet this was written for.
const EXPIRED_KEY_LOG = <<<'LOG'
Err:5 https://repo.mysql.com/apt/ubuntu noble InRelease
  The following signatures were invalid: EXPKEYSIG B7B3B788A8D3785C MySQL Release Engineering <mysql-build@oss.oracle.com>
W: GPG error: https://repo.mysql.com/apt/ubuntu noble InRelease: The following signatures were invalid: EXPKEYSIG B7B3B788A8D3785C
E: The repository 'https://repo.mysql.com/apt/ubuntu noble InRelease' is not signed.
LOG;

test('removes the source whose key expired and leaves the others alone', function () {
    $result = runPruner(EXPIRED_KEY_LOG, [
        'dply-mysql.list' => MYSQL_LIST,
        'mise.list' => MISE_LIST,
        'ubuntu.sources' => UBUNTU_SOURCES,
    ]);

    expect($result['exit'])->toBe(0, $result['output'])
        ->and(file_exists($result['root'].'/dply-mysql.list'))->toBeFalse()
        ->and(file_exists($result['root'].'/mise.list'))->toBeTrue()
        ->and(file_exists($result['root'].'/ubuntu.sources'))->toBeTrue()
        ->and($result['output'])->toContain('repo.mysql.com');
});

test('leaves everything in place for a transient mirror failure', function () {
    $log = <<<'LOG'
Err:5 https://repo.mysql.com/apt/ubuntu noble InRelease
  Could not connect to repo.mysql.com:443 (1.2.3.4), connection timed out
E: Failed to fetch https://repo.mysql.com/apt/ubuntu/dists/noble/InRelease
LOG;

    $result = runPruner($log, ['dply-mysql.list' => MYSQL_LIST, 'mise.list' => MISE_LIST]);

    // Non-zero: nothing was pruned, so the caller must not retry the update.
    expect($result['exit'])->toBe(1, $result['output'])
        ->and(file_exists($result['root'].'/dply-mysql.list'))->toBeTrue()
        ->and(file_exists($result['root'].'/mise.list'))->toBeTrue();
});

test('never removes the distro mirror, however it fails', function () {
    $log = <<<'LOG'
Err:1 http://mirrors.digitalocean.com/ubuntu noble InRelease
  The following signatures were invalid: EXPKEYSIG 871920D1991BC93C
E: The repository 'http://mirrors.digitalocean.com/ubuntu noble InRelease' is not signed.
LOG;

    $result = runPruner($log, ['ubuntu.sources' => UBUNTU_SOURCES]);

    expect(file_exists($result['root'].'/ubuntu.sources'))->toBeTrue()
        ->and($result['exit'])->toBe(1, $result['output'])
        ->and($result['output'])->toContain('needs a human');
});
