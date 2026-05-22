<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteTraefikProvisionerTest;
use \App\Models\Server;
use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\SiteTraefikProvisioner;
use App\Services\Sites\TraefikSiteConfigBuilder;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
test('provision writes backend caddy and traefik configs', function () {
    $server = new class(['name' => 'Traefik Box', 'ip_address' => '203.0.113.22', 'ssh_user' => 'root', 'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----", 'status' => Server::STATUS_READY]) extends Server
    {
        function recoverySshPrivateKey(): ?string
        {
            return null;
        }
    };

    $site = new Site([
        'name' => 'Proxy App',
        'slug' => 'proxy-app',
        'type' => SiteType::Php,
        'document_root' => '/var/www/proxy-app/public',
        'repository_path' => '/var/www/proxy-app',
        'php_version' => '8.3',
    ]);
    $site->id = '01HZYTESTTRAEFIK0000000001';
    $site->setRelation('server', $server);
    $site->setRelation('domains', new Collection([
        new SiteDomain(['hostname' => 'proxy.example.com', 'is_primary' => true]),
    ]));

    $writtenFiles = [];

    $ssh = Mockery::mock('overload:App\Services\SshConnection');
    $ssh->shouldReceive('effectiveUsername')->andReturn('root');
    $ssh->shouldReceive('putFile')
        ->zeroOrMoreTimes()
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
            // answered before the Traefik-specific marker below or the mkdir guard throws.
            if (str_contains($command, 'DPLY_PLACEHOLDER_MKDIR')) {
                return "\nDPLY_PLACEHOLDER_MKDIR:0";
            }

            return "\nDPLY_TRAEFIK_EXIT:0";
        });

    $provisioner = new SiteTraefikProvisioner(new TraefikSiteConfigBuilder, new CaddySiteConfigBuilder);
    $provisioner->provision($site);

    expect($writtenFiles)->toHaveKey('/var/www/proxy-app/public/index.html');
    expect($writtenFiles)->toHaveKey('/etc/caddy/sites-enabled/'.$site->webserverConfigBasename().'-backend.caddy');
    expect($writtenFiles)->toHaveKey('/etc/traefik/dynamic/'.$site->webserverConfigBasename().'.yml');
    $this->assertStringContainsString('proxy.example.com', $writtenFiles['/etc/traefik/dynamic/'.$site->webserverConfigBasename().'.yml']);
});
