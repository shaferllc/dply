<?php

namespace Tests\Unit\Services\GcpComputeServiceTest;

use App\Models\ProviderCredential;
use App\Services\GcpComputeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function gcpServiceAccountArray(string $projectId = 'dply-test'): array
{
    static $privateKey;

    if (! is_string($privateKey) || $privateKey === '') {
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        if ($resource === false) {
            throw new \RuntimeException('Unable to generate RSA key for tests.');
        }
        openssl_pkey_export($resource, $privateKey);
    }

    return [
        'type' => 'service_account',
        'project_id' => $projectId,
        'private_key_id' => 'test-key-id',
        'private_key' => $privateKey,
        'client_email' => 'dply-test@'.$projectId.'.iam.gserviceaccount.com',
        'client_id' => '1234567890',
        'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
        'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/dply-test%40'.$projectId.'.iam.gserviceaccount.com',
    ];
}

function gcpCredential(): ProviderCredential
{
    return ProviderCredential::factory()->create([
        'provider' => 'gcp',
        'credentials' => [
            'project_id' => 'dply-test',
            'service_account' => gcpServiceAccountArray('dply-test'),
        ],
    ]);
}

test('validate credentials requests zones api', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'gcp_access_token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ], 200),
        'https://compute.googleapis.com/compute/v1/projects/dply-test/zones*' => Http::response(['items' => []], 200),
    ]);

    (new GcpComputeService(gcpCredential()))->validateCredentials();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/projects/dply-test/zones'));
});

test('get zones returns normalized payload', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'gcp_access_token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ], 200),
        'https://compute.googleapis.com/compute/v1/projects/dply-test/zones*' => Http::response([
            'items' => [
                ['name' => 'us-central1-a', 'description' => 'US Central A'],
            ],
        ], 200),
    ]);

    $zones = (new GcpComputeService(gcpCredential()))->getZones();

    expect($zones)->toBe([
        ['id' => 'us-central1-a', 'name' => 'US Central A'],
    ]);
});

test('get machine types returns static defaults when api fails', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'gcp_access_token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ], 200),
        'https://compute.googleapis.com/compute/v1/projects/dply-test/zones/us-central1-a/machineTypes*' => Http::response([], 500),
    ]);

    $sizes = (new GcpComputeService(gcpCredential()))->getMachineTypes('us-central1-a');

    expect($sizes)->toBe(GcpComputeService::defaultMachineTypes());
});

test('create instance posts compute payload with ssh metadata', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'gcp_access_token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ], 200),
        'https://compute.googleapis.com/compute/v1/projects/dply-test/zones/us-central1-a/instances' => Http::response([
            'name' => 'operation-123',
        ], 200),
    ]);

    $id = (new GcpComputeService(gcpCredential()))->createInstance(
        name: 'app-server-abc12345',
        zone: 'us-central1-a',
        machineType: 'e2-micro',
        sshPublicKey: 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 test',
        sshUser: 'ubuntu',
    );

    expect($id)->toBe('app-server-abc12345');

    Http::assertSent(fn ($request) => $request->url() === 'https://compute.googleapis.com/compute/v1/projects/dply-test/zones/us-central1-a/instances'
        && ($request->data()['machineType'] ?? null) === 'zones/us-central1-a/machineTypes/e2-micro'
        && str_contains((string) ($request->data()['metadata']['items'][0]['value'] ?? ''), 'ubuntu:ssh-ed25519'));
});

test('get public ip extracts nat ip from instance payload', function () {
    $ip = GcpComputeService::getPublicIp([
        'networkInterfaces' => [
            ['accessConfigs' => [['natIP' => '203.0.113.91']]],
        ],
    ]);

    expect($ip)->toBe('203.0.113.91');
});

test('delete instance sends delete request', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'gcp_access_token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ], 200),
        'https://compute.googleapis.com/compute/v1/projects/dply-test/zones/us-central1-a/instances/app-server-abc12345' => Http::response([], 200),
    ]);

    (new GcpComputeService(gcpCredential()))->deleteInstance('us-central1-a', 'app-server-abc12345');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://compute.googleapis.com/compute/v1/projects/dply-test/zones/us-central1-a/instances/app-server-abc12345');
});
