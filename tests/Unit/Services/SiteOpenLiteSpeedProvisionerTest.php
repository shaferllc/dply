<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteOpenLiteSpeedProvisionerTest;
use \App\Models\Server;
use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;
use App\Services\Sites\SiteOpenLiteSpeedProvisioner;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
test('provision writes openlitespeed vhost and placeholder page', function () {
    $server = new class(['name' => 'OLS Box', 'ip_address' => '203.0.113.21', 'ssh_user' => 'root', 'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----", 'status' => Server::STATUS_READY]) extends Server
    {
        function recoverySshPrivateKey(): ?string
        {
            return null;
        }
    };

    $site = new Site([
        'name' => 'Shop',
        'slug' => 'shop',
        'type' => SiteType::Php,
        'document_root' => '/var/www/shop/public',
        'repository_path' => '/var/www/shop',
        'php_version' => '8.3',
    ]);
    $site->id = '01HZYTESTOLS00000000000001';
    $site->setRelation('server', $server);
    $site->setRelation('domains', new Collection([
        new SiteDomain(['hostname' => 'shop.example.com', 'is_primary' => true]),
    ]));

    $writtenFiles = [];

    $ssh = Mockery::mock('overload:App\Services\SshConnection');
    $ssh->shouldReceive('effectiveUsername')->andReturn('root');
    $ssh->shouldReceive('putFile')
        ->twice()
        ->andReturnUsing(function (string $remotePath, string $contents) use (&$writtenFiles): void {
            $writtenFiles[$remotePath] = $contents;
        });
    $ssh->shouldReceive('exec')
        ->zeroOrMoreTimes()
        ->andReturnUsing(function (string $command): string {
            if (str_contains($command, 'DPLY_INDEX_PLACEHOLDER_EXIT')) {
                return "missing\nDPLY_INDEX_PLACEHOLDER_EXIT:0";
            }
            // Shared placeholder mkdir step from AbstractSiteWebserverProvisioner — must be
            // answered before the OLS-specific marker below or the mkdir guard throws.
            if (str_contains($command, 'DPLY_PLACEHOLDER_MKDIR')) {
                return "\nDPLY_PLACEHOLDER_MKDIR:0";
            }

            return "\nDPLY_OLS_EXIT:0";
        });

    $provisioner = new SiteOpenLiteSpeedProvisioner(new OpenLiteSpeedSiteConfigBuilder);
    $provisioner->provision($site);

    expect($writtenFiles)->toHaveKey('/var/www/shop/public/index.html');
    expect($writtenFiles)->toHaveKey('/usr/local/lsws/conf/vhosts/'.$site->webserverConfigBasename().'/vhconf.conf');
    $this->assertStringContainsString('vhDomain                  shop.example.com', $writtenFiles['/usr/local/lsws/conf/vhosts/'.$site->webserverConfigBasename().'/vhconf.conf']);
});
