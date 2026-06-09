<?php

namespace Tests\Unit\Services\ServerSystemdServicesCatalogTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerSystemdServicesCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeServerWithMeta(array $meta = []): Server
{
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($org->id, ['role' => 'owner']);

    return Server::factory()->create([
        'organization_id' => $org->id,
        'meta' => $meta,
    ]);
}

it('normalizes custom units and merges with defaults', function () {
    $server = makeServerWithMeta([
        'default_php_version' => '8.3',
        'custom_systemd_services' => ['mysql', 'redis-server.service'],
    ]);

    $catalog = new ServerSystemdServicesCatalog;
    $units = $catalog->allowedUnitsForServer($server);

    expect($units)->toContain('mysql.service');
    expect($units)->toContain('redis-server.service');
    expect($units)->toContain('php8.3-fpm.service');
    expect($units)->toContain('nginx.service');
});

it('rejects invalid custom unit names', function () {
    $catalog = new ServerSystemdServicesCatalog;

    $this->expectException(\InvalidArgumentException::class);
    $catalog->validateAndNormalizeCustomUnit('bad;rm');
});

it('offers inline disable at boot for configured basenames', function () {
    config(['server_services.systemd_units_inline_disable_at_boot' => ['redis-server', 'memcached']]);
    $catalog = new ServerSystemdServicesCatalog;

    expect($catalog->shouldOfferInlineDisableAtBoot('redis-server.service'))->toBeTrue();
    expect($catalog->shouldOfferInlineDisableAtBoot('memcached'))->toBeTrue();
    expect($catalog->shouldOfferInlineDisableAtBoot('nginx.service'))->toBeFalse();
    expect($catalog->shouldOfferInlineDisableAtBoot('ssh.service'))->toBeFalse();
});

it('resolves dpkg package for ssh unit', function () {
    $server = makeServerWithMeta([]);
    $catalog = new ServerSystemdServicesCatalog;

    $pkg = $catalog->dpkgPackageForUnit('ssh.service', $server);

    expect($pkg)->toBe('openssh-server');
});

it('normalizes safe unit names for status', function () {
    $catalog = new ServerSystemdServicesCatalog;

    expect($catalog->assertSafeUnitNameForStatus('nginx'))->toBe('nginx.service');
    expect($catalog->assertSafeUnitNameForStatus('nginx.service'))->toBe('nginx.service');
});

it('rejects unsafe unit names for status', function () {
    $catalog = new ServerSystemdServicesCatalog;

    $this->expectException(\InvalidArgumentException::class);
    $catalog->assertSafeUnitNameForStatus('foo;rm -rf');
});

test('inventory script discovers running services', function () {
    $server = makeServerWithMeta([]);
    $catalog = new ServerSystemdServicesCatalog;

    $script = $catalog->buildInventoryScript($server);

    $this->assertStringContainsString('systemctl list-units --type=service --state=running', $script);
    $this->assertStringContainsString('systemctl is-enabled', $script);
    $this->assertStringContainsString('MainPID', $script);
    $this->assertStringContainsString('DPLY_SVC_ROW:', $script);
});

test('boot menu actions follow systemctl is enabled', function () {
    $c = new ServerSystemdServicesCatalog;

    expect($c->bootMenuShowEnableAtBoot('disabled'))->toBeTrue();
    expect($c->bootMenuShowDisableAtBoot('disabled'))->toBeFalse();

    expect($c->bootMenuShowEnableAtBoot('enabled'))->toBeFalse();
    expect($c->bootMenuShowDisableAtBoot('enabled'))->toBeTrue();

    expect($c->bootMenuShowEnableAtBoot('static'))->toBeFalse();
    expect($c->bootMenuShowDisableAtBoot('static'))->toBeTrue();

    expect($c->bootMenuShowEnableAtBoot('transient'))->toBeFalse();
    expect($c->bootMenuShowDisableAtBoot('transient'))->toBeFalse();

    expect($c->bootMenuShowEnableAtBoot(''))->toBeTrue();
    expect($c->bootMenuShowDisableAtBoot(''))->toBeTrue();

    expect($c->bootMenuShowEnableAtBoot('not-found'))->toBeFalse();
    expect($c->bootMenuShowDisableAtBoot('not-found'))->toBeFalse();
});
