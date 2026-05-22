<?php

declare(strict_types=1);

namespace Tests\Feature\RelocateSiteEnvCommandTest;
use App\Jobs\PushSiteEnvJob;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('default path uses etc dply convention', function () {
    Queue::fake();
    $site = makeSite();

    $exit = Artisan::call('dply:site:env-relocate', [
        'site' => $site->slug,
    ]);

    expect($exit)->toBe(0);
    expect($site->fresh()->env_file_path)->toBe('/etc/dply/'.$site->slug.'.env');
    Queue::assertPushed(PushSiteEnvJob::class, fn ($job) => $job->siteId === $site->id);
});
test('custom path with to option', function () {
    Queue::fake();
    $site = makeSite();

    Artisan::call('dply:site:env-relocate', [
        'site' => $site->slug,
        '--to' => '/srv/secrets/jobs.env',
    ]);

    expect($site->fresh()->env_file_path)->toBe('/srv/secrets/jobs.env');
});
test('reset clears override and does not dispatch', function () {
    Queue::fake();
    $site = makeSite(['env_file_path' => '/etc/dply/jobs.env']);

    Artisan::call('dply:site:env-relocate', [
        'site' => $site->slug,
        '--reset' => true,
    ]);

    expect($site->fresh()->env_file_path)->toBeNull();
    Queue::assertNotPushed(PushSiteEnvJob::class);
});
test('no push flag skips job dispatch', function () {
    Queue::fake();
    $site = makeSite();

    Artisan::call('dply:site:env-relocate', [
        'site' => $site->slug,
        '--to' => '/etc/dply/foo.env',
        '--no-push' => true,
    ]);

    expect($site->fresh()->env_file_path)->toBe('/etc/dply/foo.env');
    Queue::assertNotPushed(PushSiteEnvJob::class);
});
test('rejects relative path', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:env-relocate', [
        'site' => $site->slug,
        '--to' => 'etc/dply/jobs.env',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('absolute path', $output);
    expect($site->fresh()->env_file_path)->toBeNull();
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:env-relocate', ['site' => 'nope']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', $output);
});
/**
 * @param  array<string, mixed>  $attrs
 */
function makeSite(array $attrs = []): Site
{
    $server = Server::factory()->ready()->create();

    return Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'slug' => 'jobs',
    ], $attrs));
}
