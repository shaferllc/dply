<?php

declare(strict_types=1);

namespace Tests\Feature\FleetSummaryCommandTest;

use App\Enums\SiteType;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('command aggregates runtime counts across sites', function () {
    $server = Server::factory()->create(['status' => Server::STATUS_READY]);
    Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);
    Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);
    Site::factory()->create(['server_id' => $server->id, 'runtime' => 'node']);
    Site::factory()->create(['server_id' => $server->id, 'runtime' => 'python']);
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'is_default' => true,
    ]);

    $exit = Artisan::call('dply:fleet:summary', ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded['totals']['servers'])->toBe(1);
    expect($decoded['totals']['sites'])->toBe(4);
    expect($decoded['site_runtimes']['php'])->toBe(2);
    expect($decoded['site_runtimes']['node'])->toBe(1);
    expect($decoded['site_runtimes']['python'])->toBe(1);
    expect($decoded['engine_usage']['postgres'])->toBe(1);
});
test('command renders human table', function () {
    $server = Server::factory()->create(['status' => Server::STATUS_READY]);
    Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);

    $exit = Artisan::call('dply:fleet:summary');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Fleet summary', $output);
    $this->assertStringContainsString('Servers by status', $output);
    $this->assertStringContainsString('Sites by runtime', $output);
    $this->assertStringContainsString('php', $output);
});
test('command handles empty fleet', function () {
    $exit = Artisan::call('dply:fleet:summary', ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded['totals']['servers'])->toBe(0);
    expect($decoded['totals']['sites'])->toBe(0);
    expect($decoded['site_runtimes'])->toBe([]);
    expect($decoded['engine_usage'])->toBe([]);
});
test('command groups unset runtime under unset key', function () {
    $server = Server::factory()->create();
    Site::factory()->create(['server_id' => $server->id, 'runtime' => null]);
    Site::factory()->create(['server_id' => $server->id, 'runtime' => 'go']);

    $exit = Artisan::call('dply:fleet:summary', ['--json' => true]);
    $output = Artisan::output();

    $decoded = json_decode($output, true);
    expect($decoded['site_runtimes']['unset'])->toBe(1);
    expect($decoded['site_runtimes']['go'])->toBe(1);
});
test('cloud fleet section aggregates by backend and status', function () {
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => 'digitalocean_app_platform',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'public.ecr.aws/x/y:1',
        'container_port' => 8080,
        'container_backend' => 'aws_app_runner',
        'status' => Site::STATUS_CONTAINER_FAILED,
    ]);

    Artisan::call('dply:fleet:summary', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['cloud_fleet']['total'])->toBe(2);
    expect($decoded['cloud_fleet']['by_backend']['digitalocean_app_platform'])->toBe(1);
    expect($decoded['cloud_fleet']['by_backend']['aws_app_runner'])->toBe(1);
    expect($decoded['cloud_fleet']['by_status'][Site::STATUS_CONTAINER_ACTIVE])->toBe(1);
    expect($decoded['cloud_fleet']['by_status'][Site::STATUS_CONTAINER_FAILED])->toBe(1);
});
test('cloud fleet section empty when no container sites', function () {
    Artisan::call('dply:fleet:summary', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['cloud_fleet']['total'])->toBe(0);
    expect($decoded['cloud_fleet']['by_backend'])->toBe([]);
});
test('cloud fleet section breaks down by mode and counts previews', function () {
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    // 1 image-mode parent
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => 'digitalocean_app_platform',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ]);

    // 1 source-mode parent
    $parent = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => null,
        'container_port' => 8080,
        'container_backend' => 'digitalocean_app_platform',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
    ]);

    // 1 source-mode preview
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => null,
        'container_port' => 8080,
        'container_backend' => 'digitalocean_app_platform',
        'status' => Site::STATUS_CONTAINER_PROVISIONING,
        'meta' => [
            'container' => [
                'source' => ['repo' => 'acme/api', 'branch' => 'feature/x'],
                'preview_parent_site_id' => $parent->id,
                'preview_branch' => 'feature/x',
            ],
        ],
    ]);

    Artisan::call('dply:fleet:summary', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['cloud_fleet']['total'])->toBe(3);
    expect($decoded['cloud_fleet']['by_mode']['image'])->toBe(1);
    expect($decoded['cloud_fleet']['by_mode']['source'])->toBe(2);
    expect($decoded['cloud_fleet']['previews'])->toBe(1);
});
test('cloud fleet human output renders section', function () {
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => 'digitalocean_app_platform',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ]);

    Artisan::call('dply:fleet:summary');
    $output = Artisan::output();

    $this->assertStringContainsString('Dply cloud', $output);
    $this->assertStringContainsString('1 cloud container site', $output);
    $this->assertStringContainsString('digitalocean_app_platform', $output);
});
