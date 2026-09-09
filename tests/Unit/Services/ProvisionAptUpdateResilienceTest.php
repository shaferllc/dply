<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ProvisionAptUpdateResilienceTest;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Services\Servers\ServerProvisionCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A server whose provision adds the MySQL apt repo — the case that wedged a
 * real droplet: repo.mysql.com's signing key had expired, so every later
 * `apt-get update` on the box exited non-zero.
 */
function mysqlProvisionScript(): string
{
    $server = Server::factory()->create([
        'provider' => ServerProvider::DigitalOcean,
        'meta' => [
            'server_role' => 'application',
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => 'mysql84',
            'cache_service' => 'redis',
        ],
    ]);

    return implode("\n", app(ServerProvisionCommandBuilder::class)->build($server));
}

test('no provision step runs a bare apt-get update under set -e', function () {
    $joined = mysqlProvisionScript();

    // dply_apt_update tolerates an unusable third-party repo; a bare
    // `apt-get update` aborts the whole provision on one. The mise step used
    // to be the only holdout.
    expect($joined)->toContain('dply_apt_update');

    $bare = preg_grep('/(^\s*|[;&|]\s*|\|\|\s*|&&\s*)apt-get update/', explode("\n", $joined));
    $bare = array_filter($bare, fn (string $line) => ! str_contains($line, 'dply_apt_update()'));

    // The helper's own implementation is the one legitimate call site.
    expect(array_values(array_filter(
        $bare,
        fn (string $line) => ! str_contains($line, 'log=$(apt-get update -y 2>&1) || true'),
    )))->toBe([]);
});

test('an unusable mysql repo is removed instead of poisoning later apt runs', function () {
    $joined = mysqlProvisionScript();

    // dply_apt_update returns 0 on every path, so the old `if ! dply_apt_update`
    // never fired and the dead repo stayed in sources.list.d.
    expect($joined)
        ->toContain('DPLY_APT_UPDATE_STATUS')
        ->toContain('rm -f /etc/apt/sources.list.d/dply-mysql.list')
        ->not->toContain('if ! dply_apt_update; then');
});

test('a silent fall back to the distro mysql is announced', function () {
    expect(mysqlProvisionScript())->toContain('is not installable here');
});

test('the generated provision script is valid bash', function () {
    $path = tempnam(sys_get_temp_dir(), 'dply-provision-').'.sh';
    file_put_contents($path, mysqlProvisionScript()."\n");

    exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exitCode);
    unlink($path);

    expect($exitCode)->toBe(0, implode("\n", $output));
});

test('the mysql key is verified on the box before the repo is added', function () {
    $joined = mysqlProvisionScript();

    // The old script piped curl straight into the keyring: an expired key was
    // installed and trusted, and the repo it signed broke apt from then on.
    expect($joined)
        ->toContain('dply_install_apt_key')
        ->toContain('DPLY_MYSQL_KEY_OK')
        ->not->toContain('curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2023 | gpg');
});

test('a refused key leaves behind neither a keyring nor a source', function () {
    expect(mysqlProvisionScript())
        ->toContain('no usable MySQL signing key')
        ->toContain('rm -f /usr/share/keyrings/dply-mysql.gpg /etc/apt/sources.list.d/dply-mysql.list');
});

test('every configured key url is tried, newest first', function () {
    config(['server_provision.mysql_repo_key_urls' => [
        'https://example.test/KEY-A',
        'https://example.test/KEY-B',
    ]]);

    expect(mysqlProvisionScript())
        ->toContain("'https://example.test/KEY-A' 'https://example.test/KEY-B'");
});

test('a configured fingerprint is passed through to the key check', function () {
    config(['server_provision.mysql_repo_key_fingerprints' => ['DEADBEEF']]);

    expect(mysqlProvisionScript())->toContain("/usr/share/keyrings/dply-mysql.gpg 'DEADBEEF'");
});

test('with no key urls configured the repo is not attempted at all', function () {
    config(['server_provision.mysql_repo_key_urls' => []]);

    // No verifiable key means no repo; the distro package is the honest result.
    expect(mysqlProvisionScript())->not->toContain('sources.list.d/dply-mysql.list');
});

test('no stack permutation emits a bare apt-get update', function () {
    // The mise step was the last one, but "the stack I happened to test" is not
    // the same as "every stack we can provision": each webserver, database and
    // cache contributes its own install lines.
    $builder = app(ServerProvisionCommandBuilder::class);
    $offenders = [];

    foreach (['nginx', 'caddy', 'openresty', 'none'] as $webserver) {
        foreach (['mysql84', 'mysql80', 'postgres16', 'none'] as $database) {
            foreach (['redis', 'valkey', 'keydb', 'dragonfly', 'memcached', 'none'] as $cache) {
                $server = Server::factory()->make([
                    'provider' => ServerProvider::DigitalOcean,
                    'meta' => [
                        'server_role' => 'application',
                        'webserver' => $webserver,
                        'php_version' => '8.3',
                        'database' => $database,
                        'cache_service' => $cache,
                    ],
                ]);

                foreach (preg_split('/\R/', implode("\n", $builder->build($server))) ?: [] as $line) {
                    if (preg_match('/(^\s*|[;&|]\s*|\|\|\s*|&&\s*)apt-get update/', $line) === 1
                        && ! str_contains($line, 'dply_apt_update')) {
                        $offenders[] = "{$webserver}/{$database}/{$cache}: ".trim($line);
                    }
                }
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", array_slice($offenders, 0, 5)));
});
