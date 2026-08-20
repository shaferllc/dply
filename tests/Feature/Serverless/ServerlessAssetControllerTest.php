<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\ServerlessAssetControllerTest;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessAssetPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('it serves a published build asset', function () {
    Storage::fake(ServerlessAssetPublisher::DISK);

    $server = Server::factory()->create([
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
    ]);

    Storage::disk(ServerlessAssetPublisher::DISK)->put(
        $site->id.'/build/assets/app-aaaaaaaa.css',
        'body{color:red}',
    );

    $this->get('/serverless-assets/'.$site->id.'/build/assets/app-aaaaaaaa.css')
        ->assertOk()
        ->assertHeader('content-type', 'text/css; charset=utf-8')
        ->assertSee('body{color:red}', false);
});

test('it rejects path traversal', function () {
    Storage::fake(ServerlessAssetPublisher::DISK);

    $server = Server::factory()->create([
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
    ]);

    $this->get('/serverless-assets/'.$site->id.'/../'.$site->id.'/build/secret')
        ->assertNotFound();
});
