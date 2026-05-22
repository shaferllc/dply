<?php

declare(strict_types=1);

namespace Tests\Feature\SetSiteEnvCommandTest;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('command sets a new environment variable', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:env-set', [
        'site' => $site->slug,
        'assignment' => 'API_KEY=secret',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Set API_KEY', $output);

    $vars = parsed($site->fresh());
    expect($vars['API_KEY'] ?? null)->toBe('secret');
    expect($site->fresh()->env_cache_origin)->toBe('local-edit');
});
test('command updates existing variable in place', function () {
    $site = makeSite(['env_file_content' => 'API_KEY=old']);

    Artisan::call('dply:site:env-set', [
        'site' => $site->slug,
        'assignment' => 'API_KEY=new',
    ]);

    $vars = parsed($site->fresh());
    expect($vars)->toBe(['API_KEY' => 'new']);
});
test('unset flag removes variable', function () {
    $site = makeSite(['env_file_content' => 'API_KEY=something']);

    $exit = Artisan::call('dply:site:env-set', [
        'site' => $site->slug,
        'assignment' => 'API_KEY=',
        '--unset' => true,
    ]);

    expect($exit)->toBe(0);
    expect(parsed($site->fresh()))->toBe([]);
});
test('unset is a noop when variable was not set', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:env-set', [
        'site' => $site->slug,
        'assignment' => 'API_KEY=',
        '--unset' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('was not set', $output);
});
test('command preserves other keys when setting one', function () {
    $site = makeSite(['env_file_content' => "FOO=one\nBAR=two"]);

    Artisan::call('dply:site:env-set', [
        'site' => $site->slug,
        'assignment' => 'BAZ=three',
    ]);

    $vars = parsed($site->fresh());
    expect($vars)->toBe(['BAR' => 'two', 'BAZ' => 'three', 'FOO' => 'one']);
});
test('command rejects invalid assignment format', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:env-set', [
        'site' => $site->slug,
        'assignment' => 'no-equal-sign',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('KEY=VALUE', $output);
});
test('command rejects invalid key pattern', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:env-set', [
        'site' => $site->slug,
        'assignment' => 'lowercase-key=foo',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('KEY must match', $output);
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:env-set', [
        'site' => 'nope',
        'assignment' => 'X=y',
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
