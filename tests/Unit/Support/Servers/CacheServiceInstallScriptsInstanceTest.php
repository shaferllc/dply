<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Models\ServerCacheService;
use App\Support\Servers\CacheServiceInstallScripts;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Behavioural lock-in for the multi-instance install-script split. The legacy
 * single-instance wrappers (`installScript`, `uninstallScript`,
 * `configFilePathFor`, `systemdServiceFor`) must keep producing the same
 * effective scripts/paths they did before the refactor — that's how the
 * already-installed `default` instance on every existing server keeps working
 * without on-box changes.
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
    public function test_default_instance_config_path_routes_to_legacy(string $engine, string $expected): void
    {
        $this->assertSame(
            $expected,
            CacheServiceInstallScripts::instanceConfigPath($engine, ServerCacheService::DEFAULT_INSTANCE_NAME),
        );
        $this->assertSame(
            $expected,
            CacheServiceInstallScripts::configFilePathFor($engine),
            'Legacy configFilePathFor should match instanceConfigPath(default).',
        );
    }

    #[DataProvider('legacyServiceUnits')]
    public function test_default_instance_service_unit_routes_to_legacy(string $engine, string $expected): void
    {
        $this->assertSame(
            $expected,
            CacheServiceInstallScripts::instanceServiceUnit($engine, ServerCacheService::DEFAULT_INSTANCE_NAME),
        );
        $this->assertSame(
            $expected,
            CacheServiceInstallScripts::systemdServiceFor($engine),
            'Legacy systemdServiceFor should match instanceServiceUnit(default).',
        );
    }

    public function test_named_instance_config_path_uses_templated_form(): void
    {
        $this->assertSame('/etc/redis/redis-sessions.conf', CacheServiceInstallScripts::instanceConfigPath('redis', 'sessions'));
        $this->assertSame('/etc/valkey/valkey-cache.conf', CacheServiceInstallScripts::instanceConfigPath('valkey', 'cache'));
        $this->assertSame('/etc/keydb/keydb-primary.conf', CacheServiceInstallScripts::instanceConfigPath('keydb', 'primary'));
        $this->assertSame('/etc/dragonfly/dragonfly-fast.conf', CacheServiceInstallScripts::instanceConfigPath('dragonfly', 'fast'));
        $this->assertSame('/etc/memcached.conf.d/legacy.conf', CacheServiceInstallScripts::instanceConfigPath('memcached', 'legacy'));
    }

    public function test_named_instance_service_unit_uses_template_form(): void
    {
        $this->assertSame('redis-server@sessions', CacheServiceInstallScripts::instanceServiceUnit('redis', 'sessions'));
        $this->assertSame('valkey-server@cache', CacheServiceInstallScripts::instanceServiceUnit('valkey', 'cache'));
        $this->assertSame('keydb-server@primary', CacheServiceInstallScripts::instanceServiceUnit('keydb', 'primary'));
        $this->assertSame('dragonfly@fast', CacheServiceInstallScripts::instanceServiceUnit('dragonfly', 'fast'));
    }

    public function test_install_script_wrapper_combines_package_plus_default_instance(): void
    {
        $combined = CacheServiceInstallScripts::installScript('redis');
        $package = CacheServiceInstallScripts::installPackageScript('redis');
        $instance = CacheServiceInstallScripts::installInstanceScript('redis', ServerCacheService::DEFAULT_INSTANCE_NAME);

        $this->assertSame($package."\n".$instance, $combined);
        // Spot-check key invariants — apt install + ping have to be present.
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

    public function test_named_instance_uninstall_when_not_last_keeps_package(): void
    {
        $script = CacheServiceInstallScripts::uninstallInstanceScript('redis', 'sessions', isLastInstance: false);

        $this->assertStringContainsString('systemctl disable --now redis-server@sessions', $script);
        $this->assertStringContainsString('rm -f /etc/redis/redis-sessions.conf', $script);
        $this->assertStringNotContainsString('apt-get purge', $script, 'Package must NOT be purged when other instances still exist.');
    }

    public function test_named_instance_uninstall_when_last_purges_package(): void
    {
        $script = CacheServiceInstallScripts::uninstallInstanceScript('redis', 'sessions', isLastInstance: true);

        $this->assertStringContainsString('systemctl disable --now redis-server@sessions', $script);
        $this->assertStringContainsString('rm -f /etc/redis/redis-sessions.conf', $script);
        $this->assertStringContainsString('apt-get purge -y redis-server', $script);
    }

    public function test_default_instance_uninstall_when_not_last_only_disables(): void
    {
        $script = CacheServiceInstallScripts::uninstallInstanceScript('redis', ServerCacheService::DEFAULT_INSTANCE_NAME, isLastInstance: false);

        $this->assertStringContainsString('systemctl disable --now redis-server', $script);
        $this->assertStringNotContainsString('apt-get purge', $script);
    }

    public function test_named_instance_install_script_includes_port(): void
    {
        $script = CacheServiceInstallScripts::installInstanceScript('redis', 'sessions', 6380);

        $this->assertStringContainsString('redis-server@sessions', $script);
        $this->assertStringContainsString('redis-cli -p 6380 ping', $script);
    }

    public function test_memcached_named_instance_install_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CacheServiceInstallScripts::installInstanceScript('memcached', 'extra', 11212);
    }

    public function test_unsupported_engine_throws_on_install_package(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CacheServiceInstallScripts::installPackageScript('postgres');
    }

    public function test_unsupported_engine_throws_on_instance_config_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CacheServiceInstallScripts::instanceConfigPath('postgres', 'foo');
    }

    public function test_install_script_for_default_row_matches_legacy_wrapper(): void
    {
        $row = new ServerCacheService;
        $row->engine = 'redis';
        $row->name = ServerCacheService::DEFAULT_INSTANCE_NAME;
        $row->port = 6379;
        $row->auth_password = null;

        // For a default-named row, the row-aware composer must produce the
        // same script as the legacy single-instance installScript() wrapper.
        // This is the contract that keeps existing servers untouched.
        $this->assertSame(
            CacheServiceInstallScripts::installScript('redis'),
            CacheServiceInstallScripts::installScriptForRow($row),
        );
    }

    public function test_install_script_for_named_row_includes_template_unit_and_config(): void
    {
        $row = new ServerCacheService;
        $row->engine = 'redis';
        $row->name = 'sessions';
        $row->port = 6380;
        $row->auth_password = 's3cret-token-xyz';

        $script = CacheServiceInstallScripts::installScriptForRow($row);

        $this->assertStringContainsString('apt-get install -y redis-server', $script);
        $this->assertStringContainsString('/etc/systemd/system/redis-server@.service', $script);
        $this->assertStringContainsString('Description=Redis instance %i', $script);
        $this->assertStringContainsString('/etc/redis/redis-sessions.conf', $script);
        $this->assertStringContainsString('port 6380', $script);
        $this->assertStringContainsString('dir /var/lib/redis/sessions', $script);
        $this->assertStringContainsString('requirepass s3cret-token-xyz', $script);
        $this->assertStringContainsString('systemctl daemon-reload', $script);
        $this->assertStringContainsString('systemctl enable --now', $script);
        $this->assertStringContainsString('redis-cli -p 6380 ping', $script);
    }

    public function test_template_unit_content_uses_systemd_template_syntax(): void
    {
        // The %i placeholder is what systemd expands into the instance name
        // when you `systemctl enable redis-server@<name>.service`.
        foreach (['redis', 'valkey', 'keydb', 'dragonfly'] as $engine) {
            $unit = CacheServiceInstallScripts::templateUnitContent($engine);
            $this->assertStringContainsString('%i', $unit, "Engine {$engine} template unit must use %i placeholder.");
            $this->assertStringContainsString('[Install]', $unit);
            $this->assertStringContainsString('WantedBy=multi-user.target', $unit);
        }
    }

    public function test_template_unit_throws_for_memcached(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CacheServiceInstallScripts::templateUnitContent('memcached');
    }

    public function test_instance_config_content_omits_requirepass_when_null(): void
    {
        $config = CacheServiceInstallScripts::instanceConfigContent('redis', 'sessions', 6380, null);

        $this->assertStringNotContainsString('requirepass', $config);
    }

    public function test_instance_config_content_includes_requirepass_when_set(): void
    {
        $config = CacheServiceInstallScripts::instanceConfigContent('redis', 'sessions', 6380, 'hunter2-letter-pass');

        $this->assertStringContainsString('requirepass hunter2-letter-pass', $config);
    }

    public function test_instance_state_dir_uses_legacy_for_default(): void
    {
        $this->assertSame('/var/lib/redis', CacheServiceInstallScripts::instanceStateDir('redis', 'default'));
        $this->assertSame('/var/lib/valkey', CacheServiceInstallScripts::instanceStateDir('valkey', 'default'));
    }

    public function test_instance_state_dir_uses_named_subdir_for_named(): void
    {
        $this->assertSame('/var/lib/redis/sessions', CacheServiceInstallScripts::instanceStateDir('redis', 'sessions'));
        $this->assertSame('/var/lib/valkey/cache', CacheServiceInstallScripts::instanceStateDir('valkey', 'cache'));
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
        // Regression: previously the parser would fall back to returning the whole last line,
        // which leaked apt errors like "E: Unable to locate package keydb" into the version
        // field whenever the install script's exit code was wrongly 0.
        $this->assertNull(CacheServiceInstallScripts::parseVersionFromBuffer('E: Unable to locate package keydb'));
        $this->assertNull(CacheServiceInstallScripts::parseVersionFromBuffer("Reading package lists...\nE: Unable to locate package keydb-server\nE: Unable to locate package keydb"));
    }

    public function test_parse_version_walks_back_past_unrelated_trailing_lines(): void
    {
        // If the version probe line is followed by an unrelated stderr message, we should
        // still find the version by walking up from the bottom.
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
