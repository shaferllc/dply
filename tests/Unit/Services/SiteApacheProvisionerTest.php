<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Sites\ApacheSiteConfigBuilder;
use App\Services\Sites\SiteApacheProvisioner;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteApacheProvisionerTest extends TestCase
{
    #[Test]
    public function provision_writes_apache_vhost_and_placeholder_page(): void
    {
        $server = new class([
            'name' => 'Apache Box',
            'ip_address' => '203.0.113.20',
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
            'name' => 'Docs',
            'slug' => 'docs',
            'type' => SiteType::Php,
            'document_root' => '/var/www/docs/public',
            'repository_path' => '/var/www/docs',
            'php_version' => '8.3',
        ]);
        $site->id = '01HZYTESTAPACHE000000000001';
        $site->setRelation('server', $server);
        $site->setRelation('domains', new Collection([
            new SiteDomain(['hostname' => 'docs.example.com', 'is_primary' => true]),
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

                return "\nDPLY_APACHE_EXIT:0";
            });

        $provisioner = new SiteApacheProvisioner(new ApacheSiteConfigBuilder);
        $provisioner->provision($site);

        $this->assertArrayHasKey('/var/www/docs/public/index.html', $writtenFiles);
        $this->assertArrayHasKey('/etc/apache2/sites-available/'.$site->webserverConfigBasename().'.conf', $writtenFiles);
        $this->assertStringContainsString('ServerName docs.example.com', $writtenFiles['/etc/apache2/sites-available/'.$site->webserverConfigBasename().'.conf']);
    }
}
