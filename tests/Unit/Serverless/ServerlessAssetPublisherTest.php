<?php

declare(strict_types=1);

namespace Tests\Unit\Serverless\ServerlessAssetPublisherTest;

use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessAssetPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('it uploads public build onto the dply.io site_assets disk', function () {
    Storage::fake(ServerlessAssetPublisher::DISK);
    config(['dply.public_app_url' => 'https://dply.example', 'app.url' => 'https://dply.test']);

    $dir = sys_get_temp_dir().'/dply-fn-assets-'.uniqid();
    File::ensureDirectoryExists($dir.'/public/build/assets');
    File::put($dir.'/public/build/assets/app-aaaaaaaa.css', 'body{}');
    File::put($dir.'/public/build/manifest.json', '{}');

    $site = Site::factory()->create();
    $publisher = app(ServerlessAssetPublisher::class);
    $url = $publisher->publishBuild($site, $dir);

    expect($url)->toBe('https://dply.example/serverless-assets/'.$site->id);
    expect($publisher->diskName())->toBe(ServerlessAssetPublisher::DISK);
    Storage::disk(ServerlessAssetPublisher::DISK)->assertExists(
        $publisher->prefix($site).'/build/assets/app-aaaaaaaa.css'
    );
    Storage::disk(ServerlessAssetPublisher::DISK)->assertExists(
        $publisher->prefix($site).'/build/manifest.json'
    );
    expect(data_get($site->fresh()->meta, 'serverless.asset_url'))->toBe($url);

    File::deleteDirectory($dir);
});

test('it looks up the disk whose url host matches dply.io', function () {
    config([
        'dply.public_app_url' => 'https://dply.io',
        'app.url' => 'https://dply.io',
        'filesystems.disks.site_assets.url' => 'https://dply.io/site-assets',
        'filesystems.disks.public.url' => 'https://dply.io/storage',
    ]);

    expect(app(ServerlessAssetPublisher::class)->diskName())->toBe('site_assets');
});

test('it falls back to site_assets when the public origin host differs', function () {
    config([
        'dply.public_app_url' => 'https://tunnel.example',
        'filesystems.disks.site_assets.url' => 'https://dply.test/site-assets',
        'filesystems.disks.public.url' => 'https://dply.test/storage',
    ]);

    expect(app(ServerlessAssetPublisher::class)->diskName())->toBe('site_assets');
});

test('leftover serverless_assets disk calls alias the dply.io site_assets disk', function () {
    expect(config('filesystems.disks.serverless_assets.driver'))->toBe(config('filesystems.disks.site_assets.driver'));
    expect(fn () => Storage::disk('serverless_assets')->exists('noop'))->not->toThrow(\InvalidArgumentException::class);
});

test('it returns null when public build is missing', function () {
    Storage::fake(ServerlessAssetPublisher::DISK);

    $dir = sys_get_temp_dir().'/dply-fn-assets-empty-'.uniqid();
    File::ensureDirectoryExists($dir);

    expect(app(ServerlessAssetPublisher::class)->publishBuild(Site::factory()->create(), $dir))->toBeNull();

    File::deleteDirectory($dir);
});
