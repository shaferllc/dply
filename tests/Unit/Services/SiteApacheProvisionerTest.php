<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteApacheProvisionerTest;
use \App\Models\Server;
use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Sites\ApacheSiteConfigBuilder;
use App\Services\Sites\SiteApacheProvisioner;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
test('provision writes apache vhost and placeholder page', function () {
    $server = new class(['name' => 'Apache Box', 'ip_address' => '203.0.113.20', 'ssh_user' => 'root', 'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----", 'status' => Server::STATUS_READY]) extends Server
    {
        function recoverySshPrivateKey(): ?string
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
    $site->id = '01HZYTESTAPACHE00000000001';
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
        ->zeroOrMoreTimes()
        ->andReturnUsing(function (string $command): string {
            if (str_contains($command, 'DPLY_INDEX_PLACEHOLDER_EXIT')) {
                return "missing\nDPLY_INDEX_PLACEHOLDER_EXIT:0";
            }
            // The shared AbstractSiteWebserverProvisioner runs `mkdir -p <root>` for the
            // placeholder web root with its own exit marker — the test stub must answer it
            // before the apache-specific marker below, otherwise the mkdir guard rejects
            // the response and the provisioner throws.
            if (str_contains($command, 'DPLY_PLACEHOLDER_MKDIR')) {
                return "\nDPLY_PLACEHOLDER_MKDIR:0";
            }

            return "\nDPLY_APACHE_EXIT:0";
        });

    $provisioner = new SiteApacheProvisioner(new ApacheSiteConfigBuilder);
    $provisioner->provision($site);

    expect($writtenFiles)->toHaveKey('/var/www/docs/public/index.html');
    expect($writtenFiles)->toHaveKey('/etc/apache2/sites-available/'.$site->webserverConfigBasename().'.conf');
    $this->assertStringContainsString('ServerName docs.example.com', $writtenFiles['/etc/apache2/sites-available/'.$site->webserverConfigBasename().'.conf']);
});
