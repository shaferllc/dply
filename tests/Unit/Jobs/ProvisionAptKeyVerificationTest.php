<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\ProvisionAptKeyVerificationTest;

use App\Jobs\Concerns\BuildsProvisionScriptPreamble;
use App\Models\ServerProvisionRun;

/**
 * The key gate decides whether an apt repo gets added at all, so it is
 * exercised as bash against real gpg colon records rather than asserted on as
 * a string. Only the record-parsing half is sourced: the fetch half needs the
 * network, and splitting them is what makes this testable at all.
 */
function recordsUsableFunction(): string
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

    preg_match('/dply_gpg_records_usable\(\) \{.*?\n\}\n/s', $host->render(), $m);

    expect($m[0] ?? null)->not->toBeNull('dply_gpg_records_usable not found in the preamble');

    return $m[0];
}

/**
 * @param  list<string>  $fingerprints
 * @return array{exit: int, output: string}
 */
function usable(string $records, array $fingerprints = []): array
{
    $dir = sys_get_temp_dir().'/dply-key-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o755, true);
    file_put_contents($dir.'/fn.sh', "set -euo pipefail\n".recordsUsableFunction());
    file_put_contents($dir.'/records', $records);

    $args = implode(' ', array_map('escapeshellarg', $fingerprints));
    $cmd = sprintf(
        'bash -c %s 2>&1',
        escapeshellarg(sprintf(
            'source %s; dply_gpg_records_usable %s < %s',
            escapeshellarg($dir.'/fn.sh'),
            $args,
            escapeshellarg($dir.'/records'),
        )),
    );

    exec($cmd, $output, $exit);
    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);

    return ['exit' => $exit, 'output' => implode("\n", $output)];
}

const FPR = 'BCA43417C3B485DD128EC6D4B7B3B788A8D3785C';

function pubRecord(string $expiry, string $validity = '-', string $caps = 'scESC'): string
{
    return "pub:{$validity}:2048:1:B7B3B788A8D3785C:1683000000:{$expiry}::-:::{$caps}:\n"
        .'fpr:::::::::'.FPR.":\n"
        ."uid:-::::1683000000::::MySQL Release Engineering <mysql-build@oss.oracle.com>::::::::::0:\n";
}

it('accepts a signing key that never expires', function () {
    expect(usable(pubRecord('0'))['exit'])->toBe(0);
});

it('accepts a signing key whose expiry is still in the future', function () {
    expect(usable(pubRecord((string) (time() + 86400 * 365)))['exit'])->toBe(0);
});

it('rejects the expired key that caused the incident', function () {
    // repo.mysql.com's 2023 key, which apt reports as EXPKEYSIG.
    expect(usable(pubRecord((string) (time() - 86400)))['exit'])->toBe(1);
});

it('rejects a revoked or otherwise invalid key', function () {
    expect(usable(pubRecord('0', 'r'))['exit'])->toBe(1)
        ->and(usable(pubRecord('0', 'e'))['exit'])->toBe(1)
        ->and(usable(pubRecord('0', 'd'))['exit'])->toBe(1);
});

it('rejects a key with no signing capability', function () {
    // Encryption-only: it can never have signed the Release file.
    expect(usable(pubRecord('0', '-', 'e'))['exit'])->toBe(1);
});

it('accepts an expired primary key that still carries a live signing subkey', function () {
    $records = 'pub:-:2048:1:B7B3B788A8D3785C:1683000000:'.(time() - 86400)."::-:::e:\n"
        .'fpr:::::::::'.FPR.":\n"
        // Capabilities are field 12, same as a pub record.
        .'sub:-:2048:1:AAAABBBBCCCCDDDD:1683000000:'.(time() + 86400)."::-:::s:\n";

    expect(usable($records)['exit'])->toBe(0);
});

it('treats an unreadable expiry as unusable rather than as never-expiring', function () {
    expect(usable(pubRecord('not-a-timestamp'))['exit'])->toBe(1);
});

it('rejects empty input', function () {
    expect(usable('')['exit'])->toBe(1);
});

it('enforces a pinned fingerprint when one is configured', function () {
    expect(usable(pubRecord('0'), [FPR])['exit'])->toBe(0)
        ->and(usable(pubRecord('0'), [strtolower(FPR)])['exit'])->toBe(0)
        ->and(usable(pubRecord('0'), ['DEADBEEF'.substr(FPR, 8)])['exit'])->toBe(1);
});
