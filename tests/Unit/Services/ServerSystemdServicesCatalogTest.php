<?php

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerSystemdServicesCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServerSystemdServicesCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function makeServerWithMeta(array $meta = []): Server
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $user->organizations()->attach($org->id, ['role' => 'owner']);

        return Server::factory()->create([
            'organization_id' => $org->id,
            'meta' => $meta,
        ]);
    }

    #[Test]
    public function it_normalizes_custom_units_and_merges_with_defaults(): void
    {
        $server = $this->makeServerWithMeta([
            'default_php_version' => '8.3',
            'custom_systemd_services' => ['mysql', 'redis-server.service'],
        ]);

        $catalog = new ServerSystemdServicesCatalog;
        $units = $catalog->allowedUnitsForServer($server);

        $this->assertContains('mysql.service', $units);
        $this->assertContains('redis-server.service', $units);
        $this->assertContains('php8.3-fpm.service', $units);
        $this->assertContains('nginx.service', $units);
    }

    #[Test]
    public function it_rejects_invalid_custom_unit_names(): void
    {
        $catalog = new ServerSystemdServicesCatalog;

        $this->expectException(\InvalidArgumentException::class);
        $catalog->validateAndNormalizeCustomUnit('bad;rm');
    }

    #[Test]
    public function it_offers_inline_disable_at_boot_for_configured_basenames(): void
    {
        config(['server_services.systemd_units_inline_disable_at_boot' => ['redis-server', 'memcached']]);
        $catalog = new ServerSystemdServicesCatalog;

        $this->assertTrue($catalog->shouldOfferInlineDisableAtBoot('redis-server.service'));
        $this->assertTrue($catalog->shouldOfferInlineDisableAtBoot('memcached'));
        $this->assertFalse($catalog->shouldOfferInlineDisableAtBoot('nginx.service'));
        $this->assertFalse($catalog->shouldOfferInlineDisableAtBoot('ssh.service'));
    }

    #[Test]
    public function it_resolves_dpkg_package_for_ssh_unit(): void
    {
        $server = $this->makeServerWithMeta([]);
        $catalog = new ServerSystemdServicesCatalog;

        $pkg = $catalog->dpkgPackageForUnit('ssh.service', $server);

        $this->assertSame('openssh-server', $pkg);
    }

    #[Test]
    public function it_normalizes_safe_unit_names_for_status(): void
    {
        $catalog = new ServerSystemdServicesCatalog;

        $this->assertSame('nginx.service', $catalog->assertSafeUnitNameForStatus('nginx'));
        $this->assertSame('nginx.service', $catalog->assertSafeUnitNameForStatus('nginx.service'));
    }

    #[Test]
    public function it_rejects_unsafe_unit_names_for_status(): void
    {
        $catalog = new ServerSystemdServicesCatalog;

        $this->expectException(\InvalidArgumentException::class);
        $catalog->assertSafeUnitNameForStatus('foo;rm -rf');
    }

    #[Test]
    public function inventory_script_discovers_running_services(): void
    {
        $server = $this->makeServerWithMeta([]);
        $catalog = new ServerSystemdServicesCatalog;

        $script = $catalog->buildInventoryScript($server);

        $this->assertStringContainsString('systemctl list-units --type=service --state=running', $script);
        $this->assertStringContainsString('systemctl is-enabled', $script);
        $this->assertStringContainsString('MainPID', $script);
        $this->assertStringContainsString('DPLY_SVC_ROW:', $script);
    }

    #[Test]
    public function boot_menu_actions_follow_systemctl_is_enabled(): void
    {
        $c = new ServerSystemdServicesCatalog;

        $this->assertTrue($c->bootMenuShowEnableAtBoot('disabled'));
        $this->assertFalse($c->bootMenuShowDisableAtBoot('disabled'));

        $this->assertFalse($c->bootMenuShowEnableAtBoot('enabled'));
        $this->assertTrue($c->bootMenuShowDisableAtBoot('enabled'));

        $this->assertFalse($c->bootMenuShowEnableAtBoot('static'));
        $this->assertTrue($c->bootMenuShowDisableAtBoot('static'));

        $this->assertFalse($c->bootMenuShowEnableAtBoot('transient'));
        $this->assertFalse($c->bootMenuShowDisableAtBoot('transient'));

        $this->assertTrue($c->bootMenuShowEnableAtBoot(''));
        $this->assertTrue($c->bootMenuShowDisableAtBoot(''));

        $this->assertTrue($c->bootMenuShowEnableAtBoot('not-found'));
        $this->assertTrue($c->bootMenuShowDisableAtBoot('not-found'));
    }
}
