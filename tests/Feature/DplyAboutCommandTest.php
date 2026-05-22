<?php

declare(strict_types=1);

namespace Tests\Feature\DplyAboutCommandTest;
use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('json payload includes versions and counts', function () {
    Artisan::call('dply:about', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toHaveKey('dply');
    expect($decoded['dply'])->toHaveKey('version');
    expect($decoded['dply'])->toHaveKey('laravel');
    expect($decoded['dply'])->toHaveKey('php');
    expect($decoded['dply']['php'])->toBe(PHP_VERSION);
    expect($decoded)->toHaveKey('fleet');
    expect($decoded['fleet']['servers'])->toBe(0);
    expect($decoded['fleet']['sites'])->toBe(0);
});
test('command count includes dply namespaced', function () {
    Artisan::call('dply:about', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    // We just shipped this command itself — and many others. The exact
    // count is fluid as we add commands, so just sanity-check there are
    // a meaningful number.
    expect($decoded['commands']['dply_total'])->toBeGreaterThan(20);
});
test('fleet counts reflect seeded data', function () {
    $server = Server::factory()->create();
    Site::factory()->create(['server_id' => $server->id]);
    Site::factory()->create(['server_id' => $server->id]);

    Artisan::call('dply:about', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['fleet']['servers'])->toBe(1);
    expect($decoded['fleet']['sites'])->toBe(2);
});
test('fleet counts include edge breakdown', function () {
    $server = Server::factory()->create([
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    // 1 image-mode + 1 source-mode + 1 source-mode preview
    Site::factory()->create([
        'server_id' => $server->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ]);
    $parent = Site::factory()->create([
        'server_id' => $server->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => null,
        'container_port' => 8080,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => null,
        'container_port' => 8080,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_PROVISIONING,
        'meta' => [
            'container' => [
                'source' => ['repo' => 'acme/api', 'branch' => 'feature/x'],
                'preview_parent_site_id' => $parent->id,
                'preview_branch' => 'feature/x',
            ],
        ],
    ]);

    Artisan::call('dply:about', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['fleet']['edge_sites'])->toBe(3);
    expect($decoded['fleet']['edge_source_mode_sites'])->toBe(2);
    expect($decoded['fleet']['edge_preview_sites'])->toBe(1);
});
test('human output renders section headings', function () {
    $exit = Artisan::call('dply:about');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('dply', $output);
    $this->assertStringContainsString('Laravel', $output);
    $this->assertStringContainsString('Commands', $output);
    $this->assertStringContainsString('Fleet', $output);
});
