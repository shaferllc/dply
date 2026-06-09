<?php

declare(strict_types=1);

namespace Tests\Feature\RemoveSiteProcessCommandTest;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('removes a process by name', function () {
    $site = makeSite();
    $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'scale' => 1,
        'is_active' => true,
    ]);

    $exit = Artisan::call('dply:site:process-remove', [
        'site' => $site->slug,
        'name' => 'queue',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    expect($decoded['removed'])->toBe('queue');
    expect($site->processes()->where('name', 'queue')->first())->toBeNull();
});
test('refuses to remove web without force', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:process-remove', [
        'site' => $site->slug,
        'name' => 'web',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Refusing to remove', $output);
    expect($site->processes()->where('name', 'web')->first())->not->toBeNull();
});
test('force removes web', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:process-remove', [
        'site' => $site->slug,
        'name' => 'web',
        '--force' => true,
    ]);

    expect($exit)->toBe(0);
    expect($site->processes()->where('name', 'web')->first())->toBeNull();
});
test('fails when process not found', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:process-remove', [
        'site' => $site->slug,
        'name' => 'nonexistent',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Process not found', $output);
});
test('fails when site not found', function () {
    $exit = Artisan::call('dply:site:process-remove', [
        'site' => 'nope',
        'name' => 'web',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', $output);
});
function makeSite(): Site
{
    $server = Server::factory()->create();

    return Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'jobs',
    ]);
}
