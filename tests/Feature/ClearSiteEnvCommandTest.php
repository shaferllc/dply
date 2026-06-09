<?php

declare(strict_types=1);

namespace Tests\Feature\ClearSiteEnvCommandTest;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('clears all vars with force', function () {
    $site = makeSite(['env_file_content' => "A=a\nB=b"]);

    $exit = Artisan::call('dply:site:env-clear', [
        'site' => $site->slug,
        '--force' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    expect($decoded['deleted'])->toBe(2);
    expect($decoded['keys'])->toBe(['A', 'B']);
    expect(parsed($site->fresh()))->toBe([]);
});
test('refuses without force', function () {
    $site = makeSite(['env_file_content' => 'A=a']);

    $exit = Artisan::call('dply:site:env-clear', ['site' => $site->slug]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Refusing', $output);
    expect(parsed($site->fresh()))->toBe(['A' => 'a']);
});
test('dry run reports without deleting', function () {
    $site = makeSite(['env_file_content' => "A=a\nB=b"]);

    Artisan::call('dply:site:env-clear', [
        'site' => $site->slug,
        '--dry-run' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['dry_run'])->toBeTrue();
    expect($decoded['count'])->toBe(2);
    expect($decoded['deleted'])->toBe(0);
    expect(parsed($site->fresh()))->toBe(['A' => 'a', 'B' => 'b']);
});
test('clear when already empty is idempotent', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:env-clear', [
        'site' => $site->slug,
        '--force' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    expect($decoded['deleted'])->toBe(0);
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:env-clear', [
        'site' => 'nope',
        '--force' => true,
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
/**
 * @return array<string, string>
 */
function parsed(Site $site): array
{
    $vars = app(DotEnvFileParser::class)->parse((string) ($site->env_file_content ?? ''))['variables'];
    ksort($vars);

    return $vars;
}
