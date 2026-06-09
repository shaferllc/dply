<?php

namespace Tests\Unit\Support\Servers\ServerImageProviderLinodeTest;

use App\Models\ProviderCredential;
use App\Models\Server;
use App\Support\Servers\ServerImageProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function linodeServerWithCredential(): Server
{
    $credential = ProviderCredential::factory()->create([
        'provider' => 'linode',
        'credentials' => ['api_token' => 'lin_test'],
    ]);

    return Server::factory()->linode()->create([
        'provider_credential_id' => $credential->id,
        'provider_id' => '1234',
    ]);
}

test('create images the primary ext4 disk and reports bytes from the MB size', function () {
    Http::fake([
        'https://api.linode.com/v4/linode/instances/1234/disks' => Http::response([
            'data' => [
                ['id' => 1, 'filesystem' => 'swap', 'size' => 512],
                ['id' => 2, 'filesystem' => 'ext4', 'size' => 81920],
            ],
        ], 200),
        'https://api.linode.com/v4/images' => Http::response([
            'id' => 'private/98765', 'status' => 'creating',
        ], 200),
        'https://api.linode.com/v4/images/private/98765' => Http::response([
            'id' => 'private/98765', 'status' => 'available', 'size' => 2048,
        ], 200),
    ]);

    $result = (new ServerImageProvider)->create(linodeServerWithCredential(), 'nightly-image');

    expect($result['provider_image_id'])->toBe('private/98765')
        ->and($result['provider_action_id'])->toBeNull()
        ->and($result['region'])->toBeNull()
        // 2048 MB → bytes.
        ->and($result['bytes'])->toBe(2048 * 1024 * 1024);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.linode.com/v4/images'
        && $request->data()['disk_id'] === 2
        && $request->data()['label'] === 'nightly-image');
});

test('delete removes a linode image through the image api', function () {
    Http::fake([
        'https://api.linode.com/v4/images/private/98765' => Http::response([], 200),
    ]);

    (new ServerImageProvider)->delete(linodeServerWithCredential(), 'private/98765');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.linode.com/v4/images/private/98765');
});
