<?php

declare(strict_types=1);

namespace Tests\Feature\ExportSiteConfigCommandTest;
use App\Console\Commands\ExportSiteConfigCommand;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('exports runtime processes domains to stdout', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

    Artisan::call('dply:site:export-config', ['site' => $site->slug]);
    $output = trim(Artisan::output());
    $decoded = json_decode($output, true);

    expect($decoded['format_version'])->toBe(ExportSiteConfigCommand::FORMAT_VERSION);
    expect($decoded['site']['id'])->toBe($site->id);
    expect($decoded['site']['runtime'])->toBe('node');
    expect($decoded['domains'])->not->toBeEmpty();
    expect($decoded['domains'][0]['hostname'])->toBe('jobs.example.com');
});
test('masks env values by default', function () {
    $site = makeSite(['env_file_content' => 'API_KEY=super-secret']);

    Artisan::call('dply:site:export-config', ['site' => $site->slug]);
    $output = Artisan::output();
    $decoded = json_decode(trim($output), true);

    expect($decoded['with_secrets'])->toBeFalse();
    $this->assertStringNotContainsString('super-secret', $output);
    expect($decoded['environment_variables'][0]['value'])->toBe('***');
});
test('with secrets writes cleartext', function () {
    $site = makeSite(['env_file_content' => 'API_KEY=super-secret']);

    Artisan::call('dply:site:export-config', [
        'site' => $site->slug,
        '--with-secrets' => true,
    ]);
    $decoded = json_decode(trim(Artisan::output()), true);

    expect($decoded['with_secrets'])->toBeTrue();
    expect($decoded['environment_variables'][0]['value'])->toBe('super-secret');
});
test('exports processes', function () {
    $site = makeSite();

    // Site::created hook makes a 'web' process; add a worker.
    $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'queue',
        'command' => 'node worker.js',
        'scale' => 2,
        'is_active' => true,
    ]);

    Artisan::call('dply:site:export-config', ['site' => $site->slug]);
    $decoded = json_decode(trim(Artisan::output()), true);

    $names = array_column($decoded['processes'], 'name');
    expect($names)->toContain('queue');
    $queueProcess = collect($decoded['processes'])->firstWhere('name', 'queue');
    expect($queueProcess['scale'])->toBe(2);
});
test('writes to file with to option', function () {
    $site = makeSite();
    $path = sys_get_temp_dir().'/dply-config-'.uniqid().'.json';

    Artisan::call('dply:site:export-config', [
        'site' => $site->slug,
        '--to' => $path,
    ]);

    expect($path)->toBeFile();
    expect(file_get_contents($path))->not->toBeEmpty();
    unlink($path);
});
test('refuses to overwrite without force', function () {
    $site = makeSite();
    $path = sys_get_temp_dir().'/dply-config-'.uniqid().'.json';
    file_put_contents($path, 'preexisting');

    $exit = Artisan::call('dply:site:export-config', [
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
    $exit = Artisan::call('dply:site:export-config', ['site' => 'nope']);
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
        'runtime' => 'node',
        'runtime_version' => '20.10.0',
    ], $attrs));
}
