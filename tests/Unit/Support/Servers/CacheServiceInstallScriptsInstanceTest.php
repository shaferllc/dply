<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers\CacheServiceInstallScriptsInstanceTest;

use App\Models\ServerCacheService;
use App\Support\Servers\CacheServiceInstallScripts;

/**
 * @return iterable<string, array{string, string}>
 */
dataset('legacyConfigPaths', function () {
    yield 'redis' => ['redis', '/etc/redis/redis.conf'];
    yield 'valkey' => ['valkey', '/etc/valkey/valkey.conf'];
    yield 'memcached' => ['memcached', '/etc/memcached.conf'];
    yield 'keydb' => ['keydb', '/etc/keydb/keydb.conf'];
    yield 'dragonfly' => ['dragonfly', '/etc/dragonfly/dragonfly.conf'];
});
/**
 * @return iterable<string, array{string, string}>
 */
dataset('legacyServiceUnits', function () {
    yield 'redis' => ['redis', 'redis-server'];
    yield 'valkey' => ['valkey', 'valkey-server'];
    yield 'memcached' => ['memcached', 'memcached'];
    yield 'keydb' => ['keydb', 'keydb-server'];
    yield 'dragonfly' => ['dragonfly', 'dragonfly'];
});
test('instance config path returns engine default', function (string $engine, string $expected) {
    expect(CacheServiceInstallScripts::instanceConfigPath($engine))->toBe($expected);
    expect(CacheServiceInstallScripts::instanceConfigPath($engine, 'any-historic-name'))->toBe($expected);
    expect(CacheServiceInstallScripts::configFilePathFor($engine))->toBe($expected, 'configFilePathFor and instanceConfigPath must agree on the legacy path.');
})->with('legacyConfigPaths');
test('instance service unit returns engine default', function (string $engine, string $expected) {
    expect(CacheServiceInstallScripts::instanceServiceUnit($engine))->toBe($expected);
    expect(CacheServiceInstallScripts::instanceServiceUnit($engine, 'any-historic-name'))->toBe($expected);
    expect(CacheServiceInstallScripts::systemdServiceFor($engine))->toBe($expected, 'systemdServiceFor and instanceServiceUnit must agree on the legacy unit.');
})->with('legacyServiceUnits');
test('install script wrapper combines package plus legacy instance', function () {
    $combined = CacheServiceInstallScripts::installScript('redis');

    $this->assertStringContainsString('apt-get install -y redis-server', $combined);
    $this->assertStringContainsString('systemctl enable --now redis-server', $combined);
    $this->assertStringContainsString('redis-cli ping', $combined);
});
test('uninstall script wrapper routes to default instance purge', function () {
    $combined = CacheServiceInstallScripts::uninstallScript('redis');

    $this->assertStringContainsString('systemctl disable --now redis-server', $combined);
    $this->assertStringContainsString('apt-get purge -y redis-server', $combined);
});
test('unsupported engine throws on install package', function () {
    $this->expectException(\InvalidArgumentException::class);
    CacheServiceInstallScripts::installPackageScript('postgres');
});
test('unsupported engine throws on instance config path', function () {
    $this->expectException(\InvalidArgumentException::class);
    CacheServiceInstallScripts::instanceConfigPath('postgres');
});
test('install script for row matches legacy wrapper', function () {
    $row = new ServerCacheService;
    $row->engine = 'redis';
    $row->name = ServerCacheService::DEFAULT_INSTANCE_NAME;
    $row->port = 6379;
    $row->auth_password = null;

    // Post-collapse, `installScriptForRow` is just `installPackageScript` + the legacy
    // single-instance install — identical to `installScript($engine)`. Asserting they're
    // byte-equal pins the contract so future divergence is intentional.
    expect(CacheServiceInstallScripts::installScriptForRow($row))->toBe(CacheServiceInstallScripts::installScript('redis'));
});
test('parse version extracts redis cli version', function () {
    expect(CacheServiceInstallScripts::parseVersionFromBuffer("redis-cli 7.0.5\n"))->toBe('7.0.5');
});
test('parse version extracts memcached version', function () {
    expect(CacheServiceInstallScripts::parseVersionFromBuffer("memcached 1.6.18\n"))->toBe('1.6.18');
});
test('parse version strips dragonfly v prefix', function () {
    expect(CacheServiceInstallScripts::parseVersionFromBuffer("dragonfly v1.13.0\n"))->toBe('1.13.0');
});
test('parse version returns null when buffer is empty', function () {
    expect(CacheServiceInstallScripts::parseVersionFromBuffer(''))->toBeNull();
    expect(CacheServiceInstallScripts::parseVersionFromBuffer("\n\n"))->toBeNull();
});
test('parse version returns null for apt error messages', function () {
    // Regression: the parser used to fall back to returning the whole last line, which leaked
    // apt errors like "E: Unable to locate package keydb" into the version field whenever the
    // install script's exit code was wrongly 0.
    expect(CacheServiceInstallScripts::parseVersionFromBuffer('E: Unable to locate package keydb'))->toBeNull();
    expect(CacheServiceInstallScripts::parseVersionFromBuffer("Reading package lists...\nE: Unable to locate package keydb-server\nE: Unable to locate package keydb"))->toBeNull();
});
test('parse version walks back past unrelated trailing lines', function () {
    // If the version probe line is followed by an unrelated stderr message we should still
    // find the version by walking up from the bottom.
    $buffer = "redis-cli 7.0.5\nWarning: something benign happened on stderr";
    expect(CacheServiceInstallScripts::parseVersionFromBuffer($buffer))->toBe('7.0.5');
});
test('parse version from probe output ignores everything before the marker', function () {
    // Regression: the install job used to parse the ENTIRE install buffer, so with an
    // empty probe (cli not on PATH) an apt byte count like "1607453" leaked into the
    // version field. Content before the marker must never be considered.
    $marker = CacheServiceInstallScripts::VERSION_PROBE_MARKER;

    $buffer = "Fetched 1607453 B in 2s\nSetting up valkey (8.0.1) ...\n{$marker}\n";
    expect(CacheServiceInstallScripts::parseVersionFromProbeOutput($buffer))->toBeNull();

    $buffer = "Fetched 1607453 B in 2s\n{$marker}\nvalkey-cli 8.0.1\n";
    expect(CacheServiceInstallScripts::parseVersionFromProbeOutput($buffer))->toBe('8.0.1');
});
test('parse version from probe output falls back to whole buffer without marker', function () {
    expect(CacheServiceInstallScripts::parseVersionFromProbeOutput("redis-cli 7.0.5\n"))->toBe('7.0.5');
});
test('valkey and keydb version probes never fall back to redis-cli', function () {
    // A redis-cli fallback reports a co-resident Redis version as the engine's own.
    expect(CacheServiceInstallScripts::versionProbeScript('valkey'))->not->toContain('redis-cli');
    expect(CacheServiceInstallScripts::versionProbeScript('keydb'))->not->toContain('redis-cli');
});
test('valkey and keydb install verification pings never fall back to redis-cli', function () {
    // Same false positive at install time: Redis answering on the shared default port
    // let a failed valkey/keydb start exit 0 and mark the row RUNNING.
    expect(CacheServiceInstallScripts::installScript('valkey'))->not->toContain('redis-cli');
    expect(CacheServiceInstallScripts::installScript('keydb'))->not->toContain('redis-cli');
});
test('unit candidates mirror the install script fallback chains', function () {
    expect(CacheServiceInstallScripts::unitCandidatesFor('valkey'))->toBe(['valkey-server', 'valkey']);
    expect(CacheServiceInstallScripts::unitCandidatesFor('keydb'))->toBe(['keydb-server', 'keydb']);
    expect(CacheServiceInstallScripts::unitCandidatesFor('memcached'))->toBe(['memcached']);
});
test('resolve unit snippet probes each candidate and defaults to the preferred name', function () {
    $snippet = CacheServiceInstallScripts::resolveUnitSnippet('valkey');

    expect($snippet)->toContain("UNIT='valkey-server'");
    expect($snippet)->toContain("'valkey-server' 'valkey'");
    expect($snippet)->toContain('systemctl cat');
});
test('install package script verifies keydb binary exists', function () {
    // Belt-and-suspenders: even if `||`-chained apt failures slip past `set -e`, the
    // explicit binary check has to abort the script.
    $script = CacheServiceInstallScripts::installPackageScript('keydb');
    $this->assertStringContainsString('command -v keydb-server', $script);
    $this->assertStringContainsString('exit 1', $script);
});
test('install package script verifies each engines binary', function () {
    $expected = [
        'redis' => 'redis-server',
        'valkey' => 'valkey-server',
        'memcached' => 'memcached',
        'keydb' => 'keydb-server',
        'dragonfly' => 'dragonfly',
    ];
    foreach ($expected as $engine => $binary) {
        $script = CacheServiceInstallScripts::installPackageScript($engine);
        $this->assertStringContainsString("command -v {$binary}", $script, "Missing binary check for {$engine}");
    }
});
