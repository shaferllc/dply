<?php

declare(strict_types=1);

namespace Tests\Feature\SiteDoctorCommandTest;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('json report contains runtime database processes envcounts', function () {
    $server = Server::factory()->create();
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'is_default' => true,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'runtime' => 'node',
        'runtime_version' => '20.10.0',
        'build_command' => 'npm run build',
        'start_command' => 'node server.js',
        'internal_port' => 3000,
        'database_engine' => 'postgres',
    ]);

    // Site::created hook auto-creates a 'web' process. Tweak it
    // to scale=2 and add an inactive worker alongside it.
    $site->processes()->where('name', 'web')->update(['scale' => 2, 'is_active' => true]);
    SiteProcess::factory()->create([
        'site_id' => $site->id,
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'queue',
        'is_active' => false,
        'scale' => 1,
    ]);
    $site->forceFill(['env_file_content' => 'A=x'])->save();
    $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

    Artisan::call('dply:site:doctor', [
        'site' => $site->slug,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['runtime']['key'])->toBe('node');
    expect($decoded['runtime']['version'])->toBe('20.10.0');
    expect($decoded['runtime']['internal_port'])->toBe(3000);
    expect($decoded['database']['engine'])->toBe('postgres');
    expect($decoded['database']['server_has_engine'])->toBeTrue();
    expect($decoded['processes']['total'])->toBe(2);
    expect($decoded['processes']['active'])->toBe(1);
    expect($decoded['processes']['total_scale'])->toBe(2);
    expect($decoded['env_var_counts']['cached_keys'])->toBe(1);
    expect($decoded['env_var_counts']['parse_errors'])->toBe(0);
    expect($decoded['domains'])->toHaveCount(1);
    expect($decoded['domains'][0]['hostname'])->toBe('jobs.example.com');
    expect($decoded['domains'][0]['is_primary'])->toBeTrue();
    expect($decoded['domains'][0]['url'])->toBe('https://jobs.example.com');
    expect($decoded['drift'])->toBe([]);
});
test('no domains surfaces as drift', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);

    Artisan::call('dply:site:doctor', [
        'site' => $site->slug,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['domains'])->toBe([]);
    expect($decoded['drift'])->not->toBeEmpty();
    $this->assertStringContainsString('domain-add', implode(' ', $decoded['drift']));
});
test('drift reports unregistered database engine', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'runtime' => 'php',
        'database_engine' => 'mysql',
    ]);

    Artisan::call('dply:site:doctor', [
        'site' => $site->slug,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['database']['server_has_engine'])->toBeFalse();
    expect($decoded['drift'])->not->toBeEmpty();
    $this->assertStringContainsString('mysql', $decoded['drift'][0]);
});
test('latest deployment summary appears when present', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);
    $deployment = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'status' => 'success',
        'trigger' => 'manual',
        'started_at' => now()->subMinutes(5),
        'finished_at' => now(),
        'phase_results' => [
            'build' => ['ok' => true, 'steps' => []],
            'release' => ['ok' => true, 'steps' => []],
        ],
    ]);

    Artisan::call('dply:site:doctor', [
        'site' => $site->slug,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['latest_deployment'])->not->toBeNull();
    expect($decoded['latest_deployment']['id'])->toBe($deployment->id);
    expect($decoded['latest_deployment']['phases_recorded'])->toBe(['build', 'release']);
});
test('latest deployment is null when none exists', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);

    Artisan::call('dply:site:doctor', [
        'site' => $site->slug,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['latest_deployment'])->toBeNull();
});
test('human output renders section headings', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);

    $exit = Artisan::call('dply:site:doctor', ['site' => $site->slug]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Site doctor for', $output);
    $this->assertStringContainsString('Runtime', $output);
    $this->assertStringContainsString('Database', $output);
    $this->assertStringContainsString('Processes', $output);
    $this->assertStringContainsString('Latest deployment', $output);
    $this->assertStringContainsString('Environment variables', $output);
    $this->assertStringContainsString('Domains', $output);
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:doctor', ['site' => 'nope']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', $output);
});
test('drift reports env file inside docroot', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'runtime' => 'php',
        'document_root' => '/var/www/jobs/public',
        'repository_path' => '/var/www/jobs',
        'env_file_path' => '/var/www/jobs/public/.env',
    ]);
    $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

    Artisan::call('dply:site:doctor', [
        'site' => $site->slug,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['env_location']['in_docroot'])->toBeTrue();
    expect($decoded['env_location']['path'])->toBe('/var/www/jobs/public/.env');
    expect($decoded['drift'])->not->toBeEmpty();
    $this->assertStringContainsString('inside the docroot', implode(' ', $decoded['drift']));
});
test('drift does not report env when relocated outside', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'runtime' => 'php',
        'document_root' => '/var/www/jobs/public',
        'repository_path' => '/var/www/jobs',
        'env_file_path' => '/etc/dply/jobs.env',
    ]);
    $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

    Artisan::call('dply:site:doctor', [
        'site' => $site->slug,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['env_location']['in_docroot'])->toBeFalse();
    expect($decoded['env_location']['overridden'])->toBeTrue();
    $envDrift = collect($decoded['drift'])->filter(fn ($d) => str_contains($d, 'docroot'));
    expect($envDrift)->toHaveCount(0);
});
