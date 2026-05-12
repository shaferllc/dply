<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Models\ServerCacheService;
use App\Support\Servers\CacheServiceInstallScripts;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Behavioural lock-in for the (now single-instance) install-script surface. After the
 * family-collapse refactor there's at most one row per (server, engine) on the engine's default
 * port, so the script builders are thin — apt install + systemctl enable + ping. These tests
 * make sure the wrappers still produce the expected shape and the legacy alias methods
 * (`instanceConfigPath`, `instanceServiceUnit`) keep routing to the per-engine defaults so the
 * older callers in `CacheServiceNetworkExposure` / `CacheServicePort` don't break.
 */
class CacheServiceInstallScriptsInstanceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legacyConfigPaths(): iterable
    {
        yield 'redis' => ['redis', '/etc/redis/redis.conf'];
        yield 'valkey' => ['valkey', '/etc/valkey/valkey.conf'];
        yield 'memcached' => ['memcached', '/etc/memcached.conf'];
        yield 'keydb' => ['keydb', '/etc/keydb/keydb.conf'];
        yield 'dragonfly' => ['dragonfly', '/etc/dragonfly/dragonfly.conf'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legacyServiceUnits(): iterable
    {
        yield 'redis' => ['redis', 'redis-server'];
        yield 'valkey' => ['valkey', 'valkey-server'];
        yield 'memcached' => ['memcached', 'memcached'];
        yield 'keydb' => ['keydb', 'keydb-server'];
        yield 'dragonfly' => ['dragonfly', 'dragonfly'];
    }

    #[DataProvider('legacyConfigPaths')]
    public function test_instance_config_path_returns_engine_default(string $engine, string $expected): void
    {
        $this->assertSame($expected, CacheServiceInstallScripts::instanceConfigPath($engine));
        $this->assertSame($expected, CacheServiceInstallScripts::instanceConfigPath($engine, 'any-historic-name'));
        $this->assertSame(
            $expected,
            CacheServiceInstallScripts::configFilePathFor($engine),
            'configFilePathFor and instanceConfigPath must agree on the legacy path.',
        );
    }

    #[DataProvider('legacyServiceUnits')]
    public function test_instance_service_unit_returns_engine_default(string $engine, string $expected): void
    {
        $this->assertSame($expected, CacheServiceInstallScripts::instanceServiceUnit($engine));
        $this->assertSame($expected, CacheServiceInstallScripts::instanceServiceUnit($engine, 'any-historic-name'));
        $this->assertSame(
            $expected,
            CacheServiceInstallScripts::systemdServiceFor($engine),
            'systemdServiceFor and instanceServiceUnit must agree on the legacy unit.',
        );
    }

    public function test_install_script_wrapper_combines_package_plus_legacy_instance(): void
    {
        $combined = CacheServiceInstallScripts::installScript('redis');

        $this->assertStringContainsString('apt-get install -y redis-server', $combined);
        $this->assertStringContainsString('systemctl enable --now redis-server', $combined);
        $this->assertStringContainsString('redis-cli ping', $combined);
    }

    public function test_uninstall_script_wrapper_routes_to_default_instance_purge(): void
    {
        $combined = CacheServiceInstallScripts::uninstallScript('redis');

        $this->assertStringContainsString('systemctl disable --now redis-server', $combined);
        $this->assertStringContainsString('apt-get purge -y redis-server', $combined);
    }

    public function test_unsupported_engine_throws_on_install_package(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CacheServiceInstallScripts::installPackageScript('postgres');
    }

    public function test_unsupported_engine_throws_on_instance_config_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CacheServiceInstallScripts::instanceConfigPath('postgres');
    }

    public function test_install_script_for_row_matches_legacy_wrapper(): void
    {
        $row = new ServerCacheService;
        $row->engine = 'redis';
        $row->name = ServerCacheService::DEFAULT_INSTANCE_NAME;
        $row->port = 6379;
        $row->auth_password = null;

        // Post-collapse, `installScriptForRow` is just `installPackageScript` + the legacy
        // single-instance install — identical to `installScript($engine)`. Asserting they're
        // byte-equal pins the contract so future divergence is intentional.
        $this->assertSame(
            CacheServiceInstallScripts::installScript('redis'),
            CacheServiceInstallScripts::installScriptForRow($row),
        );
    }

    public function test_parse_version_extracts_redis_cli_version(): void
    {
        $this->assertSame('7.0.5', CacheServiceInstallScripts::parseVersionFromBuffer("redis-cli 7.0.5\n"));
    }

    public function test_parse_version_extracts_memcached_version(): void
    {
        $this->assertSame('1.6.18', CacheServiceInstallScripts::parseVersionFromBuffer("memcached 1.6.18\n"));
    }

    public function test_parse_version_strips_dragonfly_v_prefix(): void
    {
        $this->assertSame('1.13.0', CacheServiceInstallScripts::parseVersionFromBuffer("dragonfly v1.13.0\n"));
    }

    public function test_parse_version_returns_null_when_buffer_is_empty(): void
    {
        $this->assertNull(CacheServiceInstallScripts::parseVersionFromBuffer(''));
        $this->assertNull(CacheServiceInstallScripts::parseVersionFromBuffer("\n\n"));
    }

    public function test_parse_version_returns_null_for_apt_error_messages(): void
    {
        // Regression: the parser used to fall back to returning the whole last line, which leaked
        // apt errors like "E: Unable to locate package keydb" into the version field whenever the
        // install script's exit code was wrongly 0.
        $this->assertNull(CacheServiceInstallScripts::parseVersionFromBuffer('E: Unable to locate package keydb'));
        $this->assertNull(CacheServiceInstallScripts::parseVersionFromBuffer("Reading package lists...\nE: Unable to locate package keydb-server\nE: Unable to locate package keydb"));
    }

    public function test_parse_version_walks_back_past_unrelated_trailing_lines(): void
    {
        // If the version probe line is followed by an unrelated stderr message we should still
        // find the version by walking up from the bottom.
        $buffer = "redis-cli 7.0.5\nWarning: something benign happened on stderr";
        $this->assertSame('7.0.5', CacheServiceInstallScripts::parseVersionFromBuffer($buffer));
    }

    public function test_install_package_script_verifies_keydb_binary_exists(): void
    {
        // Belt-and-suspenders: even if `||`-chained apt failures slip past `set -e`, the
        // explicit binary check has to abort the script.
        $script = CacheServiceInstallScripts::installPackageScript('keydb');
        $this->assertStringContainsString('command -v keydb-server', $script);
        $this->assertStringContainsString('exit 1', $script);
    }

    public function test_install_package_script_verifies_each_engines_binary(): void
    {
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
    }
}
