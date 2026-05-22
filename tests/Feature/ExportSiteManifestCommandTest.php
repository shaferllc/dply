<?php

declare(strict_types=1);

namespace Tests\Feature\ExportSiteManifestCommandTest;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('writes manifest to stdout', function () {
    $site = makeSite(['runtime' => 'node', 'runtime_version' => '20']);

    $exit = Artisan::call('dply:site:export-manifest', ['site' => $site->slug]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('runtime: node', $output);
    $this->assertStringContainsString("version: '20'", $output);
});
test('writes manifest to file with to', function () {
    $site = makeSite(['runtime' => 'php']);

    $path = sys_get_temp_dir().'/dply-manifest-'.uniqid().'.yaml';
    $exit = Artisan::call('dply:site:export-manifest', [
        'site' => $site->slug,
        '--to' => $path,
    ]);

    expect($exit)->toBe(0);
    expect($path)->toBeFile();
    $this->assertStringContainsString('runtime: php', file_get_contents($path));

    unlink($path);
});
test('refuses to overwrite without force', function () {
    $site = makeSite(['runtime' => 'php']);

    $path = sys_get_temp_dir().'/dply-manifest-'.uniqid().'.yaml';
    file_put_contents($path, 'preexisting');

    $exit = Artisan::call('dply:site:export-manifest', [
        'site' => $site->slug,
        '--to' => $path,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Refusing to overwrite', $output);
    expect(file_get_contents($path))->toBe('preexisting');

    unlink($path);
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:export-manifest', ['site' => 'nope']);
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
