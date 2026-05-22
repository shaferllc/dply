<?php

declare(strict_types=1);

namespace Tests\Feature\DoctorOrphansCommandTest;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('clean fleet returns zero orphans', function () {
    $server = Server::factory()->create();
    Site::factory()->create(['server_id' => $server->id]);

    $exit = Artisan::call('dply:doctor:orphans', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    expect($decoded['total_orphans'])->toBe(0);
});
test('human output friendly when clean', function () {
    $server = Server::factory()->create();
    Site::factory()->create(['server_id' => $server->id]);

    Artisan::call('dply:doctor:orphans');
    $output = Artisan::output();

    $this->assertStringContainsString('No orphans detected', $output);
});
test('prune requires force even on clean fleet', function () {
    $exit = Artisan::call('dply:doctor:orphans', ['--prune' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('requires --force', $output);
});
test('json payload shape', function () {
    Artisan::call('dply:doctor:orphans', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toHaveKey('orphans');
    expect($decoded['orphans'])->toHaveKey('site_deployments');
    expect($decoded['orphans'])->toHaveKey('site_domains');
    expect($decoded['orphans'])->toHaveKey('site_processes');
    expect($decoded['orphans'])->toHaveKey('server_database_engines');
    expect($decoded['orphans'])->toHaveKey('sites_without_server');
});
