<?php

declare(strict_types=1);

namespace Tests\Unit\Serverless\ServerlessAssetPublisherTest;

use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessAssetPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('it uploads public build onto the attached site_assets disk', function () {
    Storage::fake(ServerlessAssetPublisher::DISK);
    config([
        'dply.public_app_url' => 'https://dply.example',
        'app.url' => 'https://dply.test',
        'filesystems.disks.site_assets.url' => 'https://dply.test/site-assets',
    ]);

    $dir = sys_get_temp_dir().'/dply-fn-assets-'.uniqid();
    File::ensureDirectoryExists($dir.'/public/build/assets');
    File::put($dir.'/public/build/assets/app-aaaaaaaa.css', 'body{}');
    File::put($dir.'/public/build/manifest.json', '{}');

    $site = Site::factory()->create([
        'meta' => ['serverless' => ['proxy_slug' => 'placehold']],
    ]);
    $publisher = app(ServerlessAssetPublisher::class);
    $url = $publisher->publishBuild($site, $dir);

    expect($url)->toBe($site->fresh()->serverlessFriendlyUrl());
    expect($url)->not->toContain('dply.example');
    expect($url)->not->toContain('/site-assets/');
    expect($url)->not->toContain('/serverless-assets/');
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

test('it uses the attached site_assets disk without matching the control-plane host', function () {
    config([
        'dply.public_app_url' => 'https://dply.io',
        'app.url' => 'https://dply.io',
        'filesystems.disks.site_assets.url' => 'https://dply.io/site-assets',
        'filesystems.disks.public.url' => 'https://dply.io/storage',
    ]);

    expect(app(ServerlessAssetPublisher::class)->diskName())->toBe('site_assets');
});

test('asset url uses the disk public endpoint when it is not the control plane', function () {
    config([
        'app.url' => 'https://dply.test',
        'dply.public_app_url' => 'https://dply.test',
        'filesystems.disks.site_assets.url' => 'https://cdn.assets.example/site-assets',
    ]);

    $site = Site::factory()->create();

    expect(app(ServerlessAssetPublisher::class)->assetUrl($site))
        ->toBe('https://cdn.assets.example/site-assets/serverless-assets/'.$site->id);
});

test('asset url stays on the function host when the disk url is the control plane', function () {
    config([
        'app.url' => 'https://dply.io',
        'dply.public_app_url' => 'https://dply.io',
        'filesystems.disks.site_assets.url' => 'https://dply.io/site-assets',
    ]);

    $site = Site::factory()->create([
        'meta' => ['serverless' => ['proxy_slug' => 'placehold']],
    ]);

    expect(app(ServerlessAssetPublisher::class)->assetUrl($site))
        ->toBe($site->serverlessFriendlyUrl())
        ->not->toContain('dply.io');
});

test('leftover serverless_assets disk calls alias the attached site_assets disk', function () {
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
