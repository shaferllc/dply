<?php

declare(strict_types=1);

namespace Tests\Feature\ListSiteEnvCommandTest;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('human listing masks values by default', function () {
    $site = makeSite(['env_file_content' => 'API_KEY=super-secret-12345']);

    $exit = Artisan::call('dply:site:env-list', ['site' => $site->slug]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('API_KEY', $output);
    $this->assertStringNotContainsString('super-secret-12345', $output);
    $this->assertStringContainsString('•', $output);
});
test('reveal flag prints cleartext', function () {
    $site = makeSite(['env_file_content' => 'API_KEY=super-secret-12345']);

    Artisan::call('dply:site:env-list', [
        'site' => $site->slug,
        '--reveal' => true,
    ]);
    $output = Artisan::output();

    $this->assertStringContainsString('super-secret-12345', $output);
});
test('json output returns structured payload', function () {
    $site = makeSite(['env_file_content' => "B_KEY=b-val\nA_KEY=a-val"]);

    Artisan::call('dply:site:env-list', [
        'site' => $site->slug,
        '--reveal' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(2);
    expect($decoded['revealed'])->toBeTrue();

    // Sorted by key for determinism.
    expect($decoded['variables'][0]['key'])->toBe('A_KEY');
    expect($decoded['variables'][0]['value'])->toBe('a-val');
    expect($decoded['variables'][1]['key'])->toBe('B_KEY');
});
test('empty listing emits friendly message', function () {
    $site = makeSite();

    Artisan::call('dply:site:env-list', ['site' => $site->slug]);
    $output = Artisan::output();

    $this->assertStringContainsString('No environment variables', $output);
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:env-list', ['site' => 'nope']);
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
