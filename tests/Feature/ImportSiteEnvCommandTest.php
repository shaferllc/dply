<?php

declare(strict_types=1);

namespace Tests\Feature\ImportSiteEnvCommandTest;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('merge mode creates and updates without removing', function () {
    $site = makeSite(['env_file_content' => "KEEP_ME=k\nOVERRIDE_ME=old"]);

    $file = writeEnvFile("OVERRIDE_ME=new\nNEW_ONE=fresh\n");

    $exit = Artisan::call('dply:site:env-import', [
        'site' => $site->slug,
        '--file' => $file,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    expect($decoded['mode'])->toBe('merge');
    expect($decoded['created'])->toBe(['NEW_ONE']);
    expect($decoded['updated'])->toBe(['OVERRIDE_ME']);
    expect($decoded['removed'])->toBe([]);

    expect(parsed($site->fresh()))->toBe([
        'KEEP_ME' => 'k',
        'NEW_ONE' => 'fresh',
        'OVERRIDE_ME' => 'new',
    ]);
});
test('replace mode removes keys not in file', function () {
    $site = makeSite(['env_file_content' => 'GOING_AWAY=g']);

    $file = writeEnvFile("KEPT=ok\n");

    Artisan::call('dply:site:env-import', [
        'site' => $site->slug,
        '--file' => $file,
        '--replace' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['mode'])->toBe('replace');
    expect($decoded['removed'])->toBe(['GOING_AWAY']);

    expect(parsed($site->fresh()))->toBe(['KEPT' => 'ok']);
});
test('dry run does not write', function () {
    $site = makeSite();
    $file = writeEnvFile("FRESH=val\n");

    Artisan::call('dply:site:env-import', [
        'site' => $site->slug,
        '--file' => $file,
        '--dry-run' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['dry_run'])->toBeTrue();
    expect($decoded['created'])->toBe(['FRESH']);
    expect(parsed($site->fresh()))->toBe([]);
});
test('command reports parse errors', function () {
    $site = makeSite();
    $file = writeEnvFile("MALFORMED_LINE\nGOOD=value\n");

    Artisan::call('dply:site:env-import', [
        'site' => $site->slug,
        '--file' => $file,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['created'])->toBe(['GOOD']);
    expect($decoded['errors'])->toHaveCount(1);
    expect(parsed($site->fresh()))->toBe(['GOOD' => 'value']);
});
test('command fails when file missing', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:env-import', [
        'site' => $site->slug,
        '--file' => '/tmp/dply-nonexistent-'.uniqid().'.env',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('not found', $output);
});
test('command fails when file option missing', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:env-import', ['site' => $site->slug]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('--file is required', $output);
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
function writeEnvFile(string $contents): string
{
    $path = sys_get_temp_dir().'/dply-env-import-'.uniqid().'.env';
    file_put_contents($path, $contents);

    return $path;
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
