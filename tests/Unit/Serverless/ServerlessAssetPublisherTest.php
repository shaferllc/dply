<?php

declare(strict_types=1);

namespace Tests\Unit\Serverless\ServerlessAssetPublisherTest;

use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessAssetPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Build a throwaway `public/build` tree and hand back its directory.
 */
function buildDir(string $marker = 'aaaaaaaa'): string
{
    $dir = sys_get_temp_dir().'/dply-fn-assets-'.uniqid();
    File::ensureDirectoryExists($dir.'/public/build/assets');
    File::put($dir.'/public/build/assets/app-'.$marker.'.css', 'body{}');
    File::put($dir.'/public/build/manifest.json', '{}');

    return $dir;
}

test('it uploads public build onto the serverless assets disk', function () {
    Storage::fake(ServerlessAssetPublisher::DISK);
    config([
        'dply.public_app_url' => 'https://dply.example',
        'app.url' => 'https://dply.test',
        'filesystems.disks.serverless_assets.url' => 'https://dply.test/site-assets',
    ]);

    $dir = buildDir();

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

test('it publishes under the asset label so the prefix matches the hostname', function () {
    Storage::fake(ServerlessAssetPublisher::DISK);

    $site = Site::factory()->create([
        'meta' => ['serverless' => ['proxy_slug' => 'orders-a1b2c3d4']],
    ]);

    expect(app(ServerlessAssetPublisher::class)->prefix($site))
        ->toBe('serverless-assets/orders-a1b2c3d4');
});

/**
 * The reason publishing stopped deleting: a rollback re-deploys an older
 * artifact whose Vite manifest asks for the hashed filenames that build
 * produced. If a newer publish had removed them, the rolled-back site loses
 * its CSS and JS.
 */
test('publishing keeps a previous build so a rollback can still resolve its assets', function () {
    Storage::fake(ServerlessAssetPublisher::DISK);

    $site = Site::factory()->create([
        'meta' => ['serverless' => ['proxy_slug' => 'placehold']],
    ]);
    $publisher = app(ServerlessAssetPublisher::class);

    $first = buildDir('11111111');
    $publisher->publishBuild($site, $first);
    File::deleteDirectory($first);

    $second = buildDir('22222222');
    $publisher->publishBuild($site->fresh(), $second);
    File::deleteDirectory($second);

    $prefix = $publisher->prefix($site->fresh());
    Storage::disk(ServerlessAssetPublisher::DISK)
        ->assertExists($prefix.'/build/assets/app-11111111.css')
        ->assertExists($prefix.'/build/assets/app-22222222.css');
});

test('it records every publish so the garbage collector can find the retained ones', function () {
    Storage::fake(ServerlessAssetPublisher::DISK);

    $site = Site::factory()->create([
        'meta' => ['serverless' => ['proxy_slug' => 'placehold']],
    ]);
    $publisher = app(ServerlessAssetPublisher::class);

    $first = buildDir('11111111');
    $publisher->publishBuild($site, $first);
    File::deleteDirectory($first);

    $second = buildDir('22222222');
    $publisher->publishBuild($site->fresh(), $second);
    File::deleteDirectory($second);

    expect(data_get($site->fresh()->meta, 'serverless.assets.publishes'))->toHaveCount(2);
});

test('it refuses to publish a build over the size limit', function () {
    Storage::fake(ServerlessAssetPublisher::DISK);
    config(['serverless.assets.max_bytes' => 4]);

    $dir = buildDir();
    $site = Site::factory()->create([
        'meta' => ['serverless' => ['proxy_slug' => 'placehold']],
    ]);

    expect(fn () => app(ServerlessAssetPublisher::class)->publishBuild($site, $dir))
        ->toThrow(\RuntimeException::class, 'exceeds');

    // Nothing may reach the bucket — the point of the check is that it runs
    // against the local directory before a single byte is uploaded.
    expect(Storage::disk(ServerlessAssetPublisher::DISK)->allFiles())->toBe([]);

    File::deleteDirectory($dir);
});

test('asset url uses the site cdn hostname when delivery is enabled', function () {
    config(['serverless.assets.cdn.enabled' => true]);

    $site = Site::factory()->create([
        'meta' => ['serverless' => ['proxy_slug' => 'orders-a1b2c3d4']],
    ]);

    expect(app(ServerlessAssetPublisher::class)->assetUrl($site))
        ->toStartWith('https://orders-a1b2c3d4-assets.');
});

test('asset url prefers an attached custom hostname', function () {
    config(['serverless.assets.cdn.enabled' => true]);

    $site = Site::factory()->create([
        'meta' => ['serverless' => [
            'proxy_slug' => 'orders-a1b2c3d4',
            'assets' => ['custom_hostnames' => ['cdn.acme.com']],
        ]],
    ]);

    expect(app(ServerlessAssetPublisher::class)->assetUrl($site))
        ->toBe('https://cdn.acme.com');
});

test('asset url uses the disk public endpoint when it is not the control plane', function () {
    config([
        'app.url' => 'https://dply.test',
        'dply.public_app_url' => 'https://dply.test',
        'serverless.assets.cdn.enabled' => false,
        'filesystems.disks.serverless_assets.url' => 'https://cdn.assets.example/site-assets',
    ]);

    $site = Site::factory()->create();

    expect(app(ServerlessAssetPublisher::class)->assetUrl($site))
        ->toBe('https://cdn.assets.example/site-assets/serverless-assets/'.$site->id);
});

test('asset url stays on the function host when the disk url is the control plane', function () {
    config([
        'app.url' => 'https://dply.io',
        'dply.public_app_url' => 'https://dply.io',
        'serverless.assets.cdn.enabled' => false,
        'filesystems.disks.serverless_assets.url' => 'https://dply.io/site-assets',
    ]);

    $site = Site::factory()->create([
        'meta' => ['serverless' => ['proxy_slug' => 'placehold']],
    ]);

    expect(app(ServerlessAssetPublisher::class)->assetUrl($site))
        ->toBe($site->serverlessFriendlyUrl())
        ->not->toContain('dply.io');
});

test('the serverless assets disk falls back to the attached local store when unconfigured', function () {
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

test('reads fall back to the pre-cutover prefix so an unbackfilled site still serves', function () {
    Storage::fake(ServerlessAssetPublisher::DISK);

    $site = Site::factory()->create([
        'meta' => ['serverless' => ['proxy_slug' => 'orders-a1b2c3d4']],
    ]);

    // Written where assets lived before they were keyed on the label.
    Storage::disk(ServerlessAssetPublisher::DISK)
        ->put('serverless-assets/'.$site->id.'/build/app.css', 'body{}');

    expect(app(ServerlessAssetPublisher::class)->read($site, 'build/app.css'))->toBe('body{}');
});
