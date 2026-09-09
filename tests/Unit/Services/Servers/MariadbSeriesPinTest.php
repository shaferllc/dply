<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\MariadbSeriesPinTest;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Services\Servers\ServerProvisionCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The series gate decides whether MariaDB's apt repo gets added at all, so it
 * is exercised as bash against real `apt-cache policy` version strings rather
 * than asserted on as a string — the same approach ProvisionAptKeyVerification
 * takes with the key gate.
 *
 * The case statement is pulled out of the freshly built script instead of being
 * copied here, so this cannot pass against a stale hand-written duplicate.
 */
function seriesCase(string $database): string
{
    $server = Server::factory()->create([
        'provider' => ServerProvider::DigitalOcean,
        'meta' => [
            'server_role' => 'application',
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => $database,
            'cache_service' => 'redis',
        ],
    ]);

    $joined = implode("\n", app(ServerProvisionCommandBuilder::class)->build($server));

    preg_match('/^case "\$\{DPLY_MARIADB_CANDIDATE#\*:\}".*esac$/m', $joined, $m);

    expect($m[0] ?? null)->not->toBeNull('the MariaDB series case was not emitted for '.$database);

    return $m[0];
}

/** Run the emitted gate with a given apt candidate; returns DPLY_MARIADB_PIN. */
function pinFor(string $case, string $candidate): string
{
    $script = "set -uo pipefail\n"
        .'DPLY_MARIADB_CANDIDATE='.escapeshellarg($candidate)."\n"
        ."DPLY_MARIADB_PIN=1\n"
        // The gate echoes an informational line on the match path; silence it
        // so only the flag comes back.
        .'{ '.$case.' ; } >/dev/null'."\n"
        .'printf %s "$DPLY_MARIADB_PIN"';

    exec('bash -c '.escapeshellarg($script).' 2>/dev/null', $output);

    return trim(implode('', $output));
}

// Exact strings observed from Ubuntu noble-updates and dlm.mariadb.com.
const DISTRO = '1:10.11.14-0ubuntu0.24.04.1';
const MDB114 = '1:11.4.13+maria~ubu2404';
const MDB118 = '1:11.8.9+maria~ubu2404';
const MDB1011 = '1:10.11.19+maria~ubu2404';

test('a 11.4 request is not satisfied by the distro package', function () {
    $case = seriesCase('mariadb114');

    // The reported bug: 10.11.14 answering a mariadb114 request.
    expect(pinFor($case, DISTRO))->toBe('1')
        ->and(pinFor($case, MDB114))->toBe('0')
        ->and(pinFor($case, MDB118))->toBe('1');
});

test('a bare 11 request accepts any 11.x and anchors the match', function () {
    $case = seriesCase('mariadb11');

    expect(pinFor($case, MDB118))->toBe('0')
        ->and(pinFor($case, MDB114))->toBe('0')
        ->and(pinFor($case, DISTRO))->toBe('1')
        // Unanchored "11*" would match 110.x.
        ->and(pinFor($case, '1:110.2.0'))->toBe('1');
});

test('a 10.11 request is satisfied by the distro package and adds no repo', function () {
    $case = seriesCase('mariadb1011');

    expect(pinFor($case, DISTRO))->toBe('0')
        ->and(pinFor($case, MDB1011))->toBe('0')
        ->and(pinFor($case, MDB114))->toBe('1');
});

test('the epoch is stripped before comparing', function () {
    $case = seriesCase('mariadb1011');

    // Every real candidate carries a "1:" epoch; comparing it raw matches
    // nothing, which re-adds the repo and warns on every provision.
    expect(pinFor($case, '1:10.11.14-0ubuntu0'))->toBe('0')
        ->and(pinFor($case, '10.11.14-0ubuntu0'))->toBe('0');
});

test('an empty candidate pins rather than crashing', function () {
    expect(pinFor(seriesCase('mariadb114'), ''))->toBe('1');
});

test('the pin is not written onto a server that already has mariadb', function () {
    $server = Server::factory()->create([
        'provider' => ServerProvider::DigitalOcean,
        'meta' => [
            'server_role' => 'application',
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => 'mariadb114',
            'cache_service' => 'redis',
        ],
    ]);

    $joined = implode("\n", app(ServerProvisionCommandBuilder::class)->build($server));

    // ensurePackagesInstalled() skips the install when mariadb-server is
    // already present, so an unguarded pin would leave an 11.4 repo configured
    // against a 10.11 datadir — armed for an unattended major upgrade on the
    // next apt-get upgrade.
    expect($joined)->toContain('if dpkg -s mariadb-server >/dev/null 2>&1; then');

    $guard = strpos($joined, 'if dpkg -s mariadb-server >/dev/null 2>&1; then');
    $repo = strpos($joined, 'mariadb-server/11.4/repo/ubuntu');
    expect($repo)->toBeGreaterThan($guard, 'the repo write must sit inside the already-installed guard');
});

test('force reinstall keeps the pin so the reinstall lands on the right series', function () {
    config(['server_provision.force_reinstall' => true]);

    $server = Server::factory()->create([
        'provider' => ServerProvider::DigitalOcean,
        'meta' => [
            'server_role' => 'application',
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => 'mariadb114',
            'cache_service' => 'redis',
        ],
    ]);

    $joined = implode("\n", app(ServerProvisionCommandBuilder::class)->build($server));

    expect($joined)->toContain('mariadb-server/11.4/repo/ubuntu')
        ->and($joined)->not->toContain('if dpkg -s mariadb-server >/dev/null 2>&1; then');
});
