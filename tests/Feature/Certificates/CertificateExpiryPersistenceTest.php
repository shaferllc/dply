<?php

declare(strict_types=1);

namespace Tests\Feature\Certificates;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\User;
use App\Modules\Certificates\Services\ImportedCertificateInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{0: string, 1: string} cert PEM, key PEM */
function selfSignedPemPair(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'imported.example.com'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 45, ['digest_alg' => 'sha256']);

    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem);

    return [$certPem, $keyPem];
}

test('an imported certificate records its own expiry without SSH', function (): void {
    [$certPem, $keyPem] = selfSignedPemPair();

    $user = User::factory()->create();
    $org = Organization::factory()->create();

    // Deliberately not ready: the installer takes its no-SSH branch, proving the
    // expiry comes from the PEM rather than a remote openssl call.
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => 'provisioning',
        'ssh_private_key' => null,
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $certificate = SiteCertificate::query()->create([
        'site_id' => $site->id,
        'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
        'provider_type' => SiteCertificate::PROVIDER_IMPORTED,
        'challenge_type' => SiteCertificate::CHALLENGE_IMPORTED,
        'domains_json' => ['imported.example.com'],
        'status' => SiteCertificate::STATUS_PENDING,
        'certificate_pem' => $certPem,
        'private_key_pem' => $keyPem,
    ]);

    $installed = app(ImportedCertificateInstaller::class)->execute($certificate);

    expect($installed->expires_at)->not->toBeNull()
        ->and($installed->expires_at->isFuture())->toBeTrue()
        ->and($installed->meta['expires_at_source'] ?? null)->toBe('imported_pem');
})->skip(! extension_loaded('openssl'), 'openssl extension required');
