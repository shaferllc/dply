<?php

namespace Tests\Unit\Support\Servers\ServerImageProviderVultrTest;

use App\Models\ProviderCredential;
use App\Models\Server;
use App\Support\Servers\ServerImageProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function vultrServerWithCredential(): Server
{
    $credential = ProviderCredential::factory()->create([
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    // Vultr instance IDs are UUID strings, not ints — the (int) cast DO/Hetzner
    // use would mangle this to 0, so the provider must keep it as a string.
    return Server::factory()->vultr()->create([
        'provider_credential_id' => $credential->id,
        'provider_id' => 'cb676a46-66fd-4dfb-b839-443f2e6c0b60',
    ]);
}

test('create captures a vultr snapshot and reports compressed bytes', function () {
    Http::fake([
        'https://api.vultr.com/v2/snapshots' => Http::response([
            'snapshot' => ['id' => 'snap-77', 'status' => 'pending'],
        ], 201),
        'https://api.vultr.com/v2/snapshots/snap-77' => Http::response([
            'snapshot' => [
                'id' => 'snap-77',
                'status' => 'complete',
                'size' => 42949672960,
                'compressed_size' => 949678560,
            ],
        ], 200),
    ]);

    $result = (new ServerImageProvider)->create(vultrServerWithCredential(), 'nightly-image');

    expect($result['provider_image_id'])->toBe('snap-77')
        ->and($result['provider_action_id'])->toBeNull()
        ->and($result['region'])->toBeNull()
        // Vultr bills the compressed size — that's what the cost estimate uses.
        ->and($result['bytes'])->toBe(949678560);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.vultr.com/v2/snapshots'
        && $request->data()['instance_id'] === 'cb676a46-66fd-4dfb-b839-443f2e6c0b60'
        && $request->data()['description'] === 'nightly-image');
});

test('delete removes a vultr snapshot through the image api', function () {
    Http::fake([
        'https://api.vultr.com/v2/snapshots/snap-77' => Http::response([], 204),
    ]);

    (new ServerImageProvider)->delete(vultrServerWithCredential(), 'snap-77');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.vultr.com/v2/snapshots/snap-77');
});
