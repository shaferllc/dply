<?php

namespace Tests\Unit\Services\ZeroSslHttpCertificateEngineTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\User;
use App\Services\Certificates\ImportedCertificateInstaller;
use App\Services\Certificates\ZeroSslHttpCertificateEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;

uses(RefreshDatabase::class);

test('it issues and installs a zerossl http certificate', function () {
    Config::set('services.zerossl.access_key', 'zerossl-test-key');
    Config::set('services.zerossl.poll_attempts', 1);
    Config::set('services.zerossl.poll_sleep_ms', 0);

    $user = User::factory()->create(['email' => 'owner@example.com']);
    $org = Organization::factory()->create(['email' => 'ops@example.com']);
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => 'test-private-key',
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
        'repository_path' => '/var/www/example/current',
    ]);

    $certificate = SiteCertificate::query()->create([
        'site_id' => $site->id,
        'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
        'provider_type' => SiteCertificate::PROVIDER_ZEROSSL,
        'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
        'domains_json' => ['app.example.com'],
        'status' => SiteCertificate::STATUS_PENDING,
    ]);

    Http::fake([
        'https://api.zerossl.com/validation/csr?access_key=*' => Http::response([
            'valid' => true,
            'error' => null,
            'csrResponse' => ['2048', 'sigalg=sha256WithRSAEncryption'],
        ], 200),
        'https://api.zerossl.com/certificates?access_key=*' => Http::response([
            'id' => 'zero-cert-123',
            'status' => 'draft',
            'validation' => [
                'other_methods' => [
                    'app.example.com' => [
                        'file_validation_url_http' => 'http://app.example.com/.well-known/pki-validation/abc123.txt',
                        'file_validation_content' => ['line-1', 'line-2', 'line-3'],
                    ],
                ],
            ],
        ], 200),
        'https://api.zerossl.com/certificates/zero-cert-123/challenges?access_key=*' => Http::response([
            'success' => true,
        ], 200),
        'https://api.zerossl.com/certificates/zero-cert-123?access_key=*' => Http::response([
            'id' => 'zero-cert-123',
            'status' => 'issued',
            'validation_type' => 'HTTP_CSR_HASH',
            'expires' => '2026-07-01 00:00:00',
        ], 200),
        'https://api.zerossl.com/certificates/zero-cert-123/download/json?access_key=*' => Http::response([
            'certificate.crt' => "-----BEGIN CERTIFICATE-----\nissued-cert\n-----END CERTIFICATE-----",
            'ca_bundle.crt' => "-----BEGIN CERTIFICATE-----\nchain-cert\n-----END CERTIFICATE-----",
        ], 200),
    ]);

    $installer = Mockery::mock(ImportedCertificateInstaller::class);
    $installer->shouldReceive('execute')
        ->once()
        ->withArgs(function (SiteCertificate $passed): bool {
            return $passed->provider_type === SiteCertificate::PROVIDER_ZEROSSL
                && $passed->credential_reference === 'zero-cert-123'
                && str_contains((string) $passed->certificate_pem, 'issued-cert')
                && str_contains((string) $passed->chain_pem, 'chain-cert')
                && str_contains((string) $passed->private_key_pem, 'BEGIN PRIVATE KEY');
        })
        ->andReturnUsing(function (SiteCertificate $passed): SiteCertificate {
            $passed->forceFill([
                'status' => SiteCertificate::STATUS_ACTIVE,
                'certificate_path' => '/etc/dply/certs/site/cert.crt',
                'private_key_path' => '/etc/dply/certs/site/cert.key',
                'chain_path' => '/etc/dply/certs/site/cert.chain.pem',
                'last_installed_at' => now(),
            ])->save();

            return $passed->fresh();
        });

    $publishedFiles = [];
    $commands = [];

    $engine = new class($installer, $publishedFiles, $commands) extends ZeroSslHttpCertificateEngine
    {
        public function __construct(ImportedCertificateInstaller $installer, private array &$publishedFiles, private array &$commands)
        {
            parent::__construct($installer);
        }

        public function runRemoteCommand(Server $server, string $command, int $timeout): string
        {
            $this->commands[] = compact('command', 'timeout');

            return '';
        }

        public function writeRemoteFile(Server $server, string $path, string $contents): void
        {
            $this->publishedFiles[] = compact('path', 'contents');
        }

        public function sleepMilliseconds(int $milliseconds): void {}
    };

    $result = $engine->execute($certificate->fresh());

    expect($result->status)->toBe(SiteCertificate::STATUS_ACTIVE);
    expect($result->credential_reference)->toBe('zero-cert-123');
    $this->assertStringContainsString('ZeroSSL certificate issued and installed.', (string) $result->last_output);
    expect($commands)->toHaveCount(1);
    $this->assertStringContainsString('.well-known/pki-validation', $commands[0]['command']);
    expect($publishedFiles)->toHaveCount(1);
    expect($publishedFiles[0]['path'])->toBe($site->effectiveDocumentRoot().'/.well-known/pki-validation/abc123.txt');
    expect($publishedFiles[0]['contents'])->toBe("line-1\nline-2\nline-3\n");
});
