<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OracleComputeServiceTest;

use App\Models\ProviderCredential;
use App\Services\OracleComputeService;
use Illuminate\Support\Facades\Http;

test('constructor throws when required credentials are missing', function () {
    $credential = new ProviderCredential([
        'provider' => 'oracle',
        'credentials' => [
            'tenancy_ocid' => '',
            'user_ocid' => '',
            'fingerprint' => '',
            'private_key' => '',
            'region' => '',
        ],
    ]);

    $this->expectException(\InvalidArgumentException::class);
    new OracleComputeService($credential);
});

test('validate credentials calls identity availability domains endpoint', function () {
    Http::fake([
        'https://identity.us-ashburn-1.oraclecloud.com/20160918/availabilityDomains*' => Http::response([
            ['name' => 'kIdk:US-ASHBURN-AD-1'],
        ], 200),
    ]);

    service()->validateCredentials();

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), '/availabilityDomains')
        && str_contains((string) $request->header('Authorization')[0], 'Signature version="1"'));
});

test('list shapes falls back to defaults when api fails', function () {
    Http::fake([
        'https://iaas.us-ashburn-1.oraclecloud.com/20160918/shapes*' => Http::response([
            'message' => 'forbidden',
        ], 403),
    ]);

    $shapes = service()->listShapes('kIdk:US-ASHBURN-AD-1');

    expect($shapes)->toEqual(OracleComputeService::defaultShapes());
});

test('launch instance resolves subnet and posts create request', function () {
    config(['services.oracle.default_image_id' => 'ocid1.image.oc1..test-image']);

    Http::fake([
        'https://iaas.us-ashburn-1.oraclecloud.com/20160918/subnets*' => Http::response([
            ['id' => 'ocid1.subnet.oc1..test-subnet'],
        ], 200),
        'https://iaas.us-ashburn-1.oraclecloud.com/20160918/instances' => Http::response([
            'id' => 'ocid1.instance.oc1..test-instance',
        ], 200),
    ]);

    $id = service()->launchInstance(
        displayName: 'web-1',
        availabilityDomain: 'kIdk:US-ASHBURN-AD-1',
        shape: 'VM.Standard.E2.1.Micro',
        sshPublicKey: 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExampleKey',
    );

    expect($id)->toBe('ocid1.instance.oc1..test-instance');

    Http::assertSent(fn ($request) => $request->url() === 'https://iaas.us-ashburn-1.oraclecloud.com/20160918/instances'
        && ($request->data()['shape'] ?? null) === 'VM.Standard.E2.1.Micro'
        && ($request->data()['createVnicDetails']['subnetId'] ?? null) === 'ocid1.subnet.oc1..test-subnet');
});

test('get public ip reads primary vnic attachment then vnic payload', function () {
    Http::fake([
        'https://iaas.us-ashburn-1.oraclecloud.com/20160918/vnicAttachments*' => Http::response([
            [
                'vnicId' => 'ocid1.vnic.oc1..test-vnic',
                'isPrimary' => true,
                'lifecycleState' => 'ATTACHED',
            ],
        ], 200),
        'https://iaas.us-ashburn-1.oraclecloud.com/20160918/vnics/ocid1.vnic.oc1..test-vnic' => Http::response([
            'publicIp' => '203.0.113.44',
        ], 200),
    ]);

    $ip = service()->getPublicIp('ocid1.instance.oc1..test-instance');

    expect($ip)->toBe('203.0.113.44');
});

test('terminate instance sends delete request', function () {
    Http::fake([
        'https://iaas.us-ashburn-1.oraclecloud.com/20160918/instances/ocid1.instance.oc1..test-instance' => Http::response([], 204),
    ]);

    service()->terminateInstance('ocid1.instance.oc1..test-instance');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://iaas.us-ashburn-1.oraclecloud.com/20160918/instances/ocid1.instance.oc1..test-instance');
});

function service(): OracleComputeService
{
    $credential = new ProviderCredential([
        'provider' => 'oracle',
        'credentials' => [
            'tenancy_ocid' => 'ocid1.tenancy.oc1..exampleuniqueID',
            'user_ocid' => 'ocid1.user.oc1..exampleuniqueID',
            'fingerprint' => '12:34:56:78:90:ab:cd:ef:12:34:56:78:90:ab:cd:ef',
            'private_key' => oracleTestPrivateKey(),
            'region' => 'us-ashburn-1',
            'compartment_id' => 'ocid1.compartment.oc1..exampleuniqueID',
        ],
    ]);

    return new OracleComputeService($credential);
}

function oracleTestPrivateKey(): string
{
    static $privateKey = null;
    if (is_string($privateKey) && $privateKey !== '') {
        return $privateKey;
    }

    $resource = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);

    if ($resource === false) {
        throw new \RuntimeException('Unable to generate test private key.');
    }

    $exported = '';
    openssl_pkey_export($resource, $exported);
    openssl_pkey_free($resource);
    $privateKey = $exported;

    return $privateKey;
}
