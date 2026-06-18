<?php

namespace Tests\Unit\Services\AzureComputeServiceTest;

use App\Models\ProviderCredential;
use App\Modules\Cloud\Services\AzureComputeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function azureCredential(): ProviderCredential
{
    return ProviderCredential::factory()->create([
        'provider' => 'azure',
        'credentials' => [
            'tenant_id' => 'tenant-123',
            'client_id' => 'client-123',
            'client_secret' => 'secret-123',
            'subscription_id' => 'sub-123',
        ],
    ]);
}

test('validate credentials requests azure locations api', function () {
    Http::fake([
        'https://login.microsoftonline.com/*/oauth2/token' => Http::response([
            'access_token' => 'azure_token',
            'expires_in' => 3600,
        ], 200),
        'https://management.azure.com/subscriptions/*/locations*' => Http::response([
            'value' => [],
        ], 200),
    ]);

    (new AzureComputeService(azureCredential()))->validateCredentials();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/subscriptions/sub-123/locations'));
});

test('list locations returns normalized payload', function () {
    Http::fake([
        'https://login.microsoftonline.com/*/oauth2/token' => Http::response([
            'access_token' => 'azure_token',
            'expires_in' => 3600,
        ], 200),
        'https://management.azure.com/subscriptions/*/locations*' => Http::response([
            'value' => [
                ['name' => 'eastus', 'displayName' => 'East US'],
            ],
        ], 200),
    ]);

    $locations = (new AzureComputeService(azureCredential()))->listLocations();

    expect($locations)->toBe([
        ['id' => 'eastus', 'name' => 'East US'],
    ]);
});

test('list vm sizes returns static defaults when api fails', function () {
    Http::fake([
        'https://login.microsoftonline.com/*/oauth2/token' => Http::response([
            'access_token' => 'azure_token',
            'expires_in' => 3600,
        ], 200),
        'https://management.azure.com/subscriptions/*/providers/Microsoft.Compute/locations/*/vmSizes*' => Http::response([], 500),
    ]);

    $sizes = (new AzureComputeService(azureCredential()))->listVmSizes('eastus');

    expect($sizes)->toBe(AzureComputeService::defaultVmSizes());
});

test('create linux vm creates public ip nic and vm', function () {
    Http::fake([
        'https://login.microsoftonline.com/*/oauth2/token' => Http::response([
            'access_token' => 'azure_token',
            'expires_in' => 3600,
        ], 200),
        'https://management.azure.com/subscriptions/*/resourceGroups/*/providers/Microsoft.Network/publicIPAddresses/*' => Http::response([
            'id' => '/subscriptions/sub-123/resourceGroups/dply/providers/Microsoft.Network/publicIPAddresses/app-server-pip-abc123',
        ], 201),
        'https://management.azure.com/subscriptions/*/resourceGroups/*/providers/Microsoft.Network/networkInterfaces/*' => Http::response([
            'id' => '/subscriptions/sub-123/resourceGroups/dply/providers/Microsoft.Network/networkInterfaces/app-server-nic-abc123',
        ], 201),
        'https://management.azure.com/subscriptions/*/resourceGroups/*/providers/Microsoft.Compute/virtualMachines/*' => Http::response([
            'id' => '/subscriptions/sub-123/resourceGroups/dply/providers/Microsoft.Compute/virtualMachines/app-server',
        ], 201),
    ]);

    $created = (new AzureComputeService(azureCredential()))->createLinuxVm(
        resourceGroup: 'dply',
        location: 'eastus',
        vmName: 'app-server',
        size: 'Standard_B1s',
        adminUsername: 'azureuser',
        sshPublicKey: 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 test',
    );

    expect($created['vm_id'])->toContain('/virtualMachines/app-server');
    expect($created['nic_id'])->toContain('/networkInterfaces/');
    expect($created['pip_id'])->toContain('/publicIPAddresses/');

    Http::assertSentCount(4);
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/resourceGroups/dply/providers/Microsoft.Compute/virtualMachines/app-server'));
});

test('get vm public ip returns address when present', function () {
    Http::fake([
        'https://login.microsoftonline.com/*/oauth2/token' => Http::response([
            'access_token' => 'azure_token',
            'expires_in' => 3600,
        ], 200),
        'https://management.azure.com/subscriptions/*/resourceGroups/*/providers/Microsoft.Network/publicIPAddresses/*' => Http::response([
            'properties' => ['ipAddress' => '203.0.113.88'],
        ], 200),
    ]);

    $ip = (new AzureComputeService(azureCredential()))->getVmPublicIp('dply', 'app-pip');

    expect($ip)->toBe('203.0.113.88');
});

test('delete vm sends delete request', function () {
    Http::fake([
        'https://login.microsoftonline.com/*/oauth2/token' => Http::response([
            'access_token' => 'azure_token',
            'expires_in' => 3600,
        ], 200),
        'https://management.azure.com/subscriptions/*/resourceGroups/*/providers/Microsoft.Compute/virtualMachines/*' => Http::response([], 202),
    ]);

    (new AzureComputeService(azureCredential()))->deleteVm('dply', 'app-server');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/resourceGroups/dply/providers/Microsoft.Compute/virtualMachines/app-server'));
});
