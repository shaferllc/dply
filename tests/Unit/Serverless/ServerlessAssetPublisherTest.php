<?php

declare(strict_types=1);

namespace Tests\Unit\Serverless\ServerlessAssetPublisherTest;

use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessAssetPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('it uploads public build and returns an asset url', function () {
    Storage::fake(ServerlessAssetPublisher::DISK);
    config(['dply.public_app_url' => 'https://dply.example', 'app.url' => 'https://dply.test']);

    $dir = sys_get_temp_dir().'/dply-fn-assets-'.uniqid();
    File::ensureDirectoryExists($dir.'/public/build/assets');
    File::put($dir.'/public/build/assets/app-aaaaaaaa.css', 'body{}');
    File::put($dir.'/public/build/manifest.json', '{}');

    $site = Site::factory()->create();
    $url = app(ServerlessAssetPublisher::class)->publishBuild($site, $dir);

    expect($url)->toBe('https://dply.example/serverless-assets/'.$site->id);
    Storage::disk(ServerlessAssetPublisher::DISK)->assertExists($site->id.'/build/assets/app-aaaaaaaa.css');
    Storage::disk(ServerlessAssetPublisher::DISK)->assertExists($site->id.'/build/manifest.json');
    expect(data_get($site->fresh()->meta, 'serverless.asset_url'))->toBe($url);

    File::deleteDirectory($dir);
});

test('it returns null when public build is missing', function () {
    Storage::fake(ServerlessAssetPublisher::DISK);

    $dir = sys_get_temp_dir().'/dply-fn-assets-empty-'.uniqid();
    File::ensureDirectoryExists($dir);

    expect(app(ServerlessAssetPublisher::class)->publishBuild(Site::factory()->create(), $dir))->toBeNull();

    File::deleteDirectory($dir);
});
