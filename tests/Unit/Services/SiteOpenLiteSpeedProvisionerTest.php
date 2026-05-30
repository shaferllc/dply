<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteOpenLiteSpeedProvisionerTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;
use App\Services\Sites\SiteOpenLiteSpeedProvisioner;
use App\Services\SshConnection;
use App\Services\SshConnectionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

test('provision writes openlitespeed vhost and placeholder page', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'document_root' => '/var/www/shop/public',
        'repository_path' => '/var/www/shop',
        'php_version' => '8.3',
    ]);

    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'shop.example.com',
        'is_primary' => true,
    ]);

    $writtenFiles = [];

    $ssh = Mockery::mock(SshConnection::class);
    $ssh->shouldReceive('effectiveUsername')->andReturn('root');
    $ssh->shouldReceive('putFile')
        ->times(3)
        ->andReturnUsing(function (string $remotePath, string $contents) use (&$writtenFiles): void {
            $writtenFiles[$remotePath] = $contents;
        });
    $ssh->shouldReceive('exec')
        ->zeroOrMoreTimes()
        ->andReturnUsing(function (string $command): string {
            if (str_contains($command, 'DPLY_INDEX_PLACEHOLDER_EXIT')) {
                return "missing\nDPLY_INDEX_PLACEHOLDER_EXIT:0";
            }
            if (str_contains($command, 'DPLY_PLACEHOLDER_MKDIR')) {
                return "\nDPLY_PLACEHOLDER_MKDIR:0";
            }
            if (str_contains($command, 'test -f')) {
                return "missing\n";
            }

            return "\nDPLY_OLS_EXIT:0";
        });

    $factory = Mockery::mock(SshConnectionFactory::class);
    $recoverySsh = Mockery::mock(SshConnection::class);
    $recoverySsh->shouldReceive('connect')->andReturn(false);
    $factory->shouldReceive('recoveryForServer')->andReturn($recoverySsh);
    $factory->shouldReceive('forServer')->andReturn($ssh);
    $this->app->instance(SshConnectionFactory::class, $factory);

    $provisioner = new SiteOpenLiteSpeedProvisioner(new OpenLiteSpeedSiteConfigBuilder);
    $provisioner->provision($site->fresh()->load(['domains', 'server']));

    $basename = $site->fresh()->webserverConfigBasename();

    expect($writtenFiles)->toHaveKey('/var/www/shop/public/index.html');
    expect($writtenFiles)->toHaveKey('/usr/local/lsws/conf/vhosts/'.$basename.'/vhconf.conf');
    expect($writtenFiles['/usr/local/lsws/conf/vhosts/'.$basename.'/vhconf.conf'])
        ->toContain('vhDomain                  shop.example.com');
    expect($writtenFiles)->toHaveKey('/usr/local/lsws/conf/httpd_config.conf');
    expect($writtenFiles['/usr/local/lsws/conf/httpd_config.conf'])
        ->toContain('map                     '.$basename.' shop.example.com');
});
