<?php

declare(strict_types=1);

namespace Tests\Feature\AdoptedServerSummaryLineTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Support\Servers\AdoptedServerDigest;
use App\Support\Servers\ProvisioningDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function adoptedServer(array $meta = [], string $setupStatus = Server::SETUP_STATUS_PENDING): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    return Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'setup_status' => $setupStatus,
        'meta' => array_merge(['host_kind' => Server::HOST_KIND_VM, 'adopted' => true], $meta),
    ]);
}

test('an adopted server never advertises a provisioning journey', function () {
    // Ready + setup pending is exactly the state that renders "waiting for
    // setup to start" — which never arrives for a machine dply did not build.
    $server = adoptedServer();

    expect(ProvisioningDigest::forServer($server))->toBeNull();
});

test('a server dply built still shows its journey', function () {
    $server = adoptedServer(['adopted' => false]);

    expect(ProvisioningDigest::forServer($server))->not->toBeNull();
});

test('the adopted line says it is scanning until the probe reports', function () {
    $digest = AdoptedServerDigest::forServer(adoptedServer());

    expect($digest['state'])->toBe('scanning')
        ->and($digest['detail'])->toContain('Nothing is installed or changed');
});

test('the adopted line reports what the probe found', function () {
    $digest = AdoptedServerDigest::forServer(adoptedServer([
        'manage_nginx' => ['INSTALLED' => 'yes', 'SITES_ENABLED_COUNT' => '4'],
        'manage_php_fpm' => ['versions' => [['version' => '8.3'], ['version' => '8.1']]],
        'manage_mysql' => ['INSTALLED' => 'yes'],
    ]));

    expect($digest['state'])->toBe('scanned')
        ->and($digest['detail'])->toContain('4 vhosts')
        ->and($digest['detail'])->toContain('PHP 8.3, 8.1')
        ->and($digest['detail'])->toContain('MySQL');
});

test('a server dply built gets no adopted line at all', function () {
    expect(AdoptedServerDigest::forServer(adoptedServer(['adopted' => false])))->toBeNull();
});
