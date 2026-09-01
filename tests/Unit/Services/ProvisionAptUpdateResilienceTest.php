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
