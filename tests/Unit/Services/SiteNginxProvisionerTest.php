<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Sites\NginxSiteConfigBuilder;
use App\Services\Sites\SiteNginxProvisioner;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteNginxProvisionerTest extends TestCase
{
    #[Test]
    public function provision_writes_a_placeholder_index_page_for_new_php_sites(): void
    {
        $server = new class([
            'name' => 'Web Box',
            'ip_address' => '203.0.113.10',
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
            'name' => 'Launch Pad',
            'slug' => 'launch-pad',
            'type' => SiteType::Php,
            'document_root' => '/var/www/launch-pad/public',
            'repository_path' => '/var/www/launch-pad',
            'php_version' => '8.4',
        ]);
        $site->setRelation('server', $server);
        $site->setRelation('domains', new Collection([
            new SiteDomain([
            'hostname' => 'launch.example.com',
                'is_primary' => true,
                'www_redirect' => false,
            ]),
        ]));
        $site->setRelation('redirects', new Collection);

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

                return "\nDPLY_NGINX_EXIT:0";
            });

        $provisioner = new SiteNginxProvisioner(new NginxSiteConfigBuilder);
        $provisioner->provision($site);

        $this->assertArrayHasKey('/var/www/launch-pad/public/index.html', $writtenFiles);
        $this->assertStringContainsString('Launch Pad', $writtenFiles['/var/www/launch-pad/public/index.html']);
        $this->assertStringContainsString('launch.example.com', $writtenFiles['/var/www/launch-pad/public/index.html']);
    }
}
