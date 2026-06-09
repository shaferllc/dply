<?php

declare(strict_types=1);

namespace Tests\Feature\SitePsCommandTest;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('command lists processes for a site by slug', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'jobs-app',
        'name' => 'Jobs App',
        'runtime' => 'node',
        'runtime_version' => '22.7.0',
        'internal_port' => 30005,
    ]);

    // The Site::created hook auto-creates a `web` row; backfill its
    // command and add a worker so the table has variety.
    $site->processes()->where('type', SiteProcess::TYPE_WEB)
        ->update(['command' => 'npm start']);
    $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'worker',
        'command' => 'npm run worker',
        'scale' => 1,
        'is_active' => true,
    ]);

    $exit = Artisan::call('dply:site:ps', ['site' => 'jobs-app']);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Jobs App', $output);
    $this->assertStringContainsString('node@22.7.0', $output);
    $this->assertStringContainsString('30005', $output);
    $this->assertStringContainsString('npm start', $output);
    $this->assertStringContainsString('npm run worker', $output);
});
test('command resolves site by id', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'svc',
        'runtime' => 'python',
    ]);

    $exit = Artisan::call('dply:site:ps', ['site' => $site->id]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString($site->slug, $output);
});
test('command returns failure when site not found', function () {
    $exit = Artisan::call('dply:site:ps', ['site' => 'nonexistent-slug']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('No site found', $output);
});
test('command emits machine readable json with flag', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'svc',
        'name' => 'Svc',
        'runtime' => 'go',
        'runtime_version' => '1.22',
        'internal_port' => 30009,
    ]);
    $site->processes()->where('type', SiteProcess::TYPE_WEB)
        ->update(['command' => './bin/app']);

    $exit = Artisan::call('dply:site:ps', ['site' => 'svc', '--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded)->toBeArray();
    expect($decoded['site']['runtime'])->toBe('go');
    expect($decoded['site']['runtime_version'])->toBe('1.22');
    expect($decoded['site']['internal_port'])->toBe(30009);
    expect($decoded['processes'])->not->toBeEmpty();
    expect($decoded['processes'][0]['type'])->toBe('web');
    expect($decoded['processes'][0]['command'])->toBe('./bin/app');
});
test('command orders processes web first then worker then scheduler', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'queue-app',
    ]);

    // Create out of canonical order to verify the SQL ordering.
    $site->processes()->create([
        'type' => SiteProcess::TYPE_SCHEDULER,
        'name' => 'scheduler',
        'command' => 'php artisan schedule:work',
    ]);
    $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'horizon',
        'command' => 'php artisan horizon',
    ]);

    $exit = Artisan::call('dply:site:ps', ['site' => 'queue-app', '--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    $types = array_column($decoded['processes'], 'type');

    expect($types)->toBe(['web', 'worker', 'scheduler']);
});
