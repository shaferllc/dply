<?php

declare(strict_types=1);

namespace Tests\Feature\SetSiteRepoCommandTest;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('updates url branch and path', function () {
    $site = makeSite([
        'git_repository_url' => 'git@github.com:org/old.git',
        'git_branch' => 'master',
        'repository_path' => null,
    ]);

    $exit = Artisan::call('dply:site:set-repo', [
        'site' => $site->slug,
        '--url' => 'git@github.com:org/new.git',
        '--branch' => 'main',
        '--path' => 'apps/web',
    ]);

    expect($exit)->toBe(0);
    $site->refresh();
    expect($site->git_repository_url)->toBe('git@github.com:org/new.git');
    expect($site->git_branch)->toBe('main');
    expect($site->repository_path)->toBe('apps/web');
});
test('accepts https urls', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:set-repo', [
        'site' => $site->slug,
        '--url' => 'https://github.com/org/repo.git',
    ]);

    expect($exit)->toBe(0);
    expect($site->fresh()->git_repository_url)->toBe('https://github.com/org/repo.git');
});
test('rejects garbage url', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:set-repo', [
        'site' => $site->slug,
        '--url' => 'not a url',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('does not look like a git repo', $output);
});
test('strips leading and trailing slashes from path', function () {
    $site = makeSite();

    Artisan::call('dply:site:set-repo', [
        'site' => $site->slug,
        '--path' => '/apps/web/',
    ]);

    expect($site->fresh()->repository_path)->toBe('apps/web');
});
test('empty path clears path field', function () {
    $site = makeSite(['repository_path' => 'old/path']);

    Artisan::call('dply:site:set-repo', [
        'site' => $site->slug,
        '--path' => '',
    ]);

    expect($site->fresh()->repository_path)->toBeNull();
});
test('empty branch is rejected', function () {
    $site = makeSite(['git_branch' => 'main']);

    $exit = Artisan::call('dply:site:set-repo', [
        'site' => $site->slug,
        '--branch' => '',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('cannot be empty', $output);
    expect($site->fresh()->git_branch)->toBe('main');
});
test('dry run does not persist', function () {
    $site = makeSite(['git_branch' => 'master']);

    Artisan::call('dply:site:set-repo', [
        'site' => $site->slug,
        '--branch' => 'main',
        '--dry-run' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['dry_run'])->toBeTrue();
    expect($site->fresh()->git_branch)->toBe('master');
});
test('fails when no options given', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:set-repo', ['site' => $site->slug]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Pass at least one', $output);
});
test('fails when site not found', function () {
    $exit = Artisan::call('dply:site:set-repo', [
        'site' => 'nope',
        '--branch' => 'main',
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
