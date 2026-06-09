<?php

declare(strict_types=1);

namespace Tests\Feature\SetSiteProcessCommandTest;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('creates a new process with defaults', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:process-set', [
        'site' => $site->slug,
        'name' => 'queue',
        '--command' => 'php artisan queue:work',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    expect($decoded['action'])->toBe('create');
    expect($decoded['process']['type'])->toBe(SiteProcess::TYPE_WORKER);
    expect($decoded['process']['scale'])->toBe(1);
    expect($decoded['process']['is_active'])->toBeTrue();
    expect($decoded['process']['command'])->toBe('php artisan queue:work');
});
test('updates existing process in place', function () {
    $site = makeSite();
    $existing = $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'queue',
        'command' => 'old',
        'scale' => 1,
        'is_active' => true,
    ]);

    Artisan::call('dply:site:process-set', [
        'site' => $site->slug,
        'name' => 'queue',
        '--command' => 'new',
        '--scale' => '3',
        '--active' => 'false',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['action'])->toBe('update');
    expect($decoded['process']['id'])->toBe($existing->id);
    expect($decoded['process']['command'])->toBe('new');
    expect($decoded['process']['scale'])->toBe(3);
    expect($decoded['process']['is_active'])->toBeFalse();
});
test('rejects invalid type', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:process-set', [
        'site' => $site->slug,
        'name' => 'queue',
        '--type' => 'daemon',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Invalid type', $output);
});
test('rejects invalid scale', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:process-set', [
        'site' => $site->slug,
        'name' => 'queue',
        '--scale' => '-1',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Invalid scale', $output);
});
test('rejects invalid active value', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:process-set', [
        'site' => $site->slug,
        'name' => 'queue',
        '--active' => 'maybe',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('--active must be', $output);
});
test('dry run does not persist', function () {
    $site = makeSite();

    Artisan::call('dply:site:process-set', [
        'site' => $site->slug,
        'name' => 'queue',
        '--command' => 'foo',
        '--dry-run' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['dry_run'])->toBeTrue();
    expect($site->processes()->where('name', 'queue')->first())->toBeNull();
});
test('update with no changes fails', function () {
    $site = makeSite();
    $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'queue',
        'command' => 'foo',
        'scale' => 1,
        'is_active' => true,
    ]);

    $exit = Artisan::call('dply:site:process-set', [
        'site' => $site->slug,
        'name' => 'queue',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('No changes requested', $output);
});
function makeSite(): Site
{
    $server = Server::factory()->create();

    return Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'jobs',
    ]);
}
