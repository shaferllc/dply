<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;
use App\Services\Sites\SiteOpenLiteSpeedProvisioner;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteOpenLiteSpeedProvisionerTest extends TestCase
{
    #[Test]
    public function provision_writes_openlitespeed_vhost_and_placeholder_page(): void
    {
        $server = new class([
            'name' => 'OLS Box',
            'ip_address' => '203.0.113.21',
            'ssh_user' => 'root',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'status' => Server::STATUS_READY,
        ]) extends Server
        {
            public function recoverySshPrivateKey(): ?string
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
        $site->id = '01HZYTESTOLS000000000000001';
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
            ->times(2)
            ->andReturnUsing(function (string $command): string {
                if (str_contains($command, 'DPLY_INDEX_PLACEHOLDER_EXIT')) {
                    return "missing\nDPLY_INDEX_PLACEHOLDER_EXIT:0";
                }

                return "\nDPLY_OLS_EXIT:0";
            });

        $provisioner = new SiteOpenLiteSpeedProvisioner(new OpenLiteSpeedSiteConfigBuilder);
        $provisioner->provision($site);

        $this->assertArrayHasKey('/var/www/shop/public/index.html', $writtenFiles);
        $this->assertArrayHasKey('/usr/local/lsws/conf/vhosts/'.$site->webserverConfigBasename().'/vhconf.conf', $writtenFiles);
        $this->assertStringContainsString('vhDomain                  shop.example.com', $writtenFiles['/usr/local/lsws/conf/vhosts/'.$site->webserverConfigBasename().'/vhconf.conf']);
    }
}
