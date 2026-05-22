<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Services\Servers\ServerCreatePresetCatalog;
use PHPUnit\Framework\TestCase;

class ServerCreatePresetCatalogTest extends TestCase
{
    public function test_catalog_lists_the_v1_presets_in_order(): void
    {
        $ids = array_column((new ServerCreatePresetCatalog)->all(), 'id');

        $this->assertSame([
            'laravel',
            'rails',
            'nextjs',
            'django',
            'polyglot',
            'wordpress',
            'static',
            'database',
            'custom',
        ], $ids);
    }

    public function test_wordpress_preset_uses_mariadb_redis_php_84(): void
    {
        $wp = (new ServerCreatePresetCatalog)->find(ServerCreatePresetCatalog::ID_WORDPRESS);

        $this->assertNotNull($wp);
        $this->assertSame('application', $wp['role']);
        $this->assertSame('nginx', $wp['webserver']);
        $this->assertSame('8.4', $wp['php_version']);
        $this->assertSame('mariadb114', $wp['database']);
        $this->assertSame('redis', $wp['cache']);
        $this->assertSame([], $wp['runtimes']);
        $this->assertTrue($wp['featured']);
    }

    public function test_polyglot_preset_carries_all_four_non_php_runtimes(): void
    {
        $polyglot = (new ServerCreatePresetCatalog)->find(ServerCreatePresetCatalog::ID_POLYGLOT);

        $this->assertNotNull($polyglot);
        $this->assertEqualsCanonicalizing(
            ['node', 'python', 'ruby', 'go'],
            array_keys($polyglot['runtimes']),
        );
        // Plus PHP through the dedicated php_version slot, since PHP uses
        // ondrej/php apt rather than mise.
        $this->assertSame('8.4', $polyglot['php_version']);
        $this->assertTrue($polyglot['featured']);
    }

    public function test_laravel_preset_pins_mysql_84_and_redis(): void
    {
        $laravel = (new ServerCreatePresetCatalog)->find(ServerCreatePresetCatalog::ID_LARAVEL);

        $this->assertNotNull($laravel);
        $this->assertSame('mysql84', $laravel['database']);
        $this->assertSame('redis', $laravel['cache']);
        $this->assertSame('8.4', $laravel['php_version']);
        $this->assertSame([], $laravel['runtimes']);
    }

    public function test_rails_preset_uses_postgres_17_with_ruby_runtime(): void
    {
        $rails = (new ServerCreatePresetCatalog)->find(ServerCreatePresetCatalog::ID_RAILS);

        $this->assertNotNull($rails);
        $this->assertSame('postgres17', $rails['database']);
        $this->assertSame('redis', $rails['cache']);
        $this->assertNull($rails['php_version']);
        $this->assertSame(['ruby' => '3.3'], $rails['runtimes']);
    }

    public function test_static_preset_clears_php_db_and_cache(): void
    {
        $static = (new ServerCreatePresetCatalog)->find(ServerCreatePresetCatalog::ID_STATIC);

        $this->assertNotNull($static);
        $this->assertSame('static', $static['role']);
        $this->assertNull($static['php_version']);
        $this->assertNull($static['database']);
        $this->assertNull($static['cache']);
        $this->assertSame([], $static['runtimes']);
    }

    public function test_database_node_preset_has_no_webserver(): void
    {
        $db = (new ServerCreatePresetCatalog)->find(ServerCreatePresetCatalog::ID_DATABASE);

        $this->assertNotNull($db);
        $this->assertSame('database', $db['role']);
        $this->assertNull($db['webserver']);
        $this->assertSame('postgres17', $db['database']);
    }

    public function test_custom_preset_is_empty_escape_hatch(): void
    {
        $custom = (new ServerCreatePresetCatalog)->find(ServerCreatePresetCatalog::ID_CUSTOM);

        $this->assertNotNull($custom);
        $this->assertSame('plain', $custom['role']);
        $this->assertNull($custom['webserver']);
        $this->assertNull($custom['php_version']);
        $this->assertNull($custom['database']);
        $this->assertNull($custom['cache']);
        $this->assertSame([], $custom['runtimes']);
        $this->assertFalse($custom['featured']);
    }

    public function test_to_server_meta_for_polyglot_emits_runtime_defaults(): void
    {
        $meta = (new ServerCreatePresetCatalog)->toServerMeta(ServerCreatePresetCatalog::ID_POLYGLOT);

        $this->assertSame('polyglot', $meta['preset']);
        $this->assertSame('application', $meta['server_role']);
        $this->assertSame('nginx', $meta['webserver']);
        $this->assertSame('8.4', $meta['php_version']);
        $this->assertSame('postgres17', $meta['database']);
        $this->assertSame('redis', $meta['cache_service']);
        $this->assertEqualsCanonicalizing(
            ['node', 'python', 'ruby', 'go'],
            array_keys($meta['runtime_defaults']),
        );
    }

    public function test_to_server_meta_omits_null_fields(): void
    {
        $meta = (new ServerCreatePresetCatalog)->toServerMeta(ServerCreatePresetCatalog::ID_STATIC);

        $this->assertArrayNotHasKey('php_version', $meta);
        $this->assertArrayNotHasKey('database', $meta);
        $this->assertArrayNotHasKey('cache_service', $meta);
        $this->assertArrayNotHasKey('runtime_defaults', $meta);
    }

    public function test_to_server_meta_returns_empty_for_unknown_preset(): void
    {
        $this->assertSame([], (new ServerCreatePresetCatalog)->toServerMeta('made-up-preset'));
    }

    public function test_featured_presets_include_the_polyglot_pitch(): void
    {
        $featured = array_filter(
            (new ServerCreatePresetCatalog)->all(),
            fn (array $p) => $p['featured'],
        );

        $featuredIds = array_column($featured, 'id');
        $this->assertContains('polyglot', $featuredIds);
        $this->assertContains('laravel', $featuredIds);
        $this->assertContains('rails', $featuredIds);
        $this->assertContains('nextjs', $featuredIds);
        $this->assertContains('django', $featuredIds);
    }
}
