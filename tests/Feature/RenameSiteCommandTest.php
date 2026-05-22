<?php

declare(strict_types=1);

namespace Tests\Feature\RenameSiteCommandTest;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('command updates name and slug', function () {
    $site = makeSite(['name' => 'Jobs', 'slug' => 'jobs']);

    $exit = Artisan::call('dply:site:rename', [
        'site' => $site->id,
        '--name' => 'Careers',
        '--slug' => 'careers',
    ]);

    expect($exit)->toBe(0);
    $site->refresh();
    expect($site->name)->toBe('Careers');
    expect($site->slug)->toBe('careers');
});
test('command normalizes slug', function () {
    $site = makeSite();

    Artisan::call('dply:site:rename', [
        'site' => $site->id,
        '--slug' => 'New Name With Spaces',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['changes']['slug']['to'])->toBe('new-name-with-spaces');
});
test('command rejects collision on same server', function () {
    $server = Server::factory()->create();
    Site::factory()->create(['server_id' => $server->id, 'slug' => 'taken']);
    $site = Site::factory()->create(['server_id' => $server->id, 'slug' => 'jobs']);

    $exit = Artisan::call('dply:site:rename', [
        'site' => $site->id,
        '--slug' => 'taken',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('already in use', $output);
    expect($site->fresh()->slug)->toBe('jobs');
});
test('command allows same slug on different server', function () {
    $server1 = Server::factory()->create();
    $server2 = Server::factory()->create();
    Site::factory()->create(['server_id' => $server1->id, 'slug' => 'taken']);
    $site = Site::factory()->create(['server_id' => $server2->id, 'slug' => 'jobs']);

    $exit = Artisan::call('dply:site:rename', [
        'site' => $site->id,
        '--slug' => 'taken',
    ]);

    expect($exit)->toBe(0);
    expect($site->fresh()->slug)->toBe('taken');
});
test('dry run does not persist', function () {
    $site = makeSite(['name' => 'Old']);

    Artisan::call('dply:site:rename', [
        'site' => $site->id,
        '--name' => 'New',
        '--dry-run' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['dry_run'])->toBeTrue();
    expect($site->fresh()->name)->toBe('Old');
});
test('command fails when neither option given', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:rename', ['site' => $site->id]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Pass --name or --slug', $output);
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:rename', [
        'site' => 'nope',
        '--name' => 'foo',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', $output);
});
/**
 * @param  array<string, mixed>  $attrs
 */
function makeSite(array $attrs = []): Site
{
    $server = Server::factory()->create();

    return Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'slug' => 'jobs',
    ], $attrs));
}
