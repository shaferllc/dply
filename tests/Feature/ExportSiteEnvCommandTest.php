<?php

declare(strict_types=1);

namespace Tests\Feature\ExportSiteEnvCommandTest;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('writes to stdout by default', function () {
    $site = makeSite(['env_file_content' => 'API_KEY=super-secret']);

    $exit = Artisan::call('dply:site:env-export', ['site' => $site->slug]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('API_KEY=super-secret', $output);
});
test('writes to file with to option', function () {
    $site = makeSite(['env_file_content' => 'A=1']);

    $path = sys_get_temp_dir().'/dply-export-'.uniqid().'.env';
    $exit = Artisan::call('dply:site:env-export', [
        'site' => $site->slug,
        '--to' => $path,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($path)->toBeFile();
    $this->assertStringContainsString('A=1', file_get_contents($path));
    $this->assertStringContainsString('Exported 1 variable', $output);

    unlink($path);
});
test('refuses to overwrite without force', function () {
    $site = makeSite(['env_file_content' => 'A=1']);

    $path = sys_get_temp_dir().'/dply-export-'.uniqid().'.env';
    file_put_contents($path, 'pre-existing');

    $exit = Artisan::call('dply:site:env-export', [
        'site' => $site->slug,
        '--to' => $path,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Refusing to overwrite', $output);
    expect(file_get_contents($path))->toBe('pre-existing');

    unlink($path);
});
test('force overwrites existing', function () {
    $site = makeSite(['env_file_content' => 'A=1']);

    $path = sys_get_temp_dir().'/dply-export-'.uniqid().'.env';
    file_put_contents($path, 'pre-existing');

    $exit = Artisan::call('dply:site:env-export', [
        'site' => $site->slug,
        '--to' => $path,
        '--force' => true,
    ]);

    expect($exit)->toBe(0);
    expect(file_get_contents($path))->toBe("A=1\n");

    unlink($path);
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:env-export', ['site' => 'nope']);
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
