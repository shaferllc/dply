<?php

declare(strict_types=1);

use App\Enums\ServerProvider;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\Resize\VultrResizeDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

/** Current instance: vc2-1c-2gb, 1 vCPU, 2048 MB, 55 GB disk, in ewr. */
function fakeVultrResizeApi(array $plans): void
{
    Http::fake([
        'https://api.vultr.com/v2/instances/inst-1' => Http::response([
            'instance' => [
                'id' => 'inst-1',
                'plan' => 'vc2-1c-2gb',
                'vcpu_count' => 1,
                'ram' => 2048,
                'disk' => 55,
                'region' => 'ewr',
                'status' => 'active',
                'power_status' => 'running',
                'server_status' => 'ok',
            ],
        ]),
        'https://api.vultr.com/v2/plans*' => Http::response(['plans' => $plans]),
    ]);
}

function vultrPlan(string $id, int $vcpus, int $ram, int $disk, array $locations = ['ewr']): array
{
    return [
        'id' => $id,
        'vcpu_count' => $vcpus,
        'ram' => $ram,
        'disk' => $disk,
        'monthly_cost' => 10,
        'locations' => $locations,
    ];
}

function vultrServer(): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_ok'],
    ]);

    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => ServerProvider::Vultr,
        'provider_credential_id' => $credential->id,
        'provider_id' => 'inst-1',
        'size' => 'vc2-1c-2gb',
        'region' => 'ewr',
    ]);
}

test('a plan with a smaller disk is not offered', function () {
    fakeVultrResizeApi([
        vultrPlan('vc2-1c-1gb', 1, 1024, 25),
        vultrPlan('vc2-2c-4gb', 2, 4096, 80),
    ]);

    $catalog = app(VultrResizeDriver::class)->catalog(vultrServer());

    expect(collect($catalog['options'])->pluck('slug')->all())->toBe(['vc2-2c-4gb']);
});

test('a plan not sold in the instance region is dropped', function () {
    fakeVultrResizeApi([
        vultrPlan('vc2-2c-4gb', 2, 4096, 80, ['lax']),
        vultrPlan('vc2-4c-8gb', 4, 8192, 160, ['ewr']),
    ]);

    $catalog = app(VultrResizeDriver::class)->catalog(vultrServer());

    expect(collect($catalog['options'])->pluck('slug')->all())->toBe(['vc2-4c-8gb']);
});

test('every vultr upgrade carries its disk, so it is permanent', function () {
    fakeVultrResizeApi([vultrPlan('vc2-2c-4gb', 2, 4096, 80)]);

    $catalog = app(VultrResizeDriver::class)->catalog(vultrServer());

    expect($catalog['options'][0]['grows_disk'])->toBeTrue();
});

test('vultr reboots in place rather than powering the machine off', function () {
    expect(app(VultrResizeDriver::class)->requiresPowerCycle())->toBeFalse();
});

test('the resize patches the plan and then waits for the instance to come back', function () {
    fakeVultrResizeApi([vultrPlan('vc2-2c-4gb', 2, 4096, 80)]);

    $server = vultrServer();
    $driver = app(VultrResizeDriver::class);
    $target = $driver->catalog($server)['options'][0];

    $states = [];
    $driver->execute($server, $target, function (string $s) use (&$states): void {
        $states[] = $s;
    });

    expect($states)->toBe(['resizing', 'powering_on']);

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && str_contains($request->url(), '/instances/inst-1')
        && ($request->data()['plan'] ?? null) === 'vc2-2c-4gb');
});
