<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\SourceControlRepositoryUrlTest;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Modules\SourceControl\Services\SiteGitCommitsFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function siteWithRepo(string $repositoryUrl, array $overrides = []): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    return Site::factory()->create(array_merge([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'git_repository_url' => $repositoryUrl,
        'git_branch' => 'master',
    ], $overrides));
}

test('expands the owner/name shorthand serverless create persists', function () {
    // Serverless Create stores the shorthand verbatim; the readers all parse
    // the value as a URL, so it has to come back out as one.
    $site = siteWithRepo('shaferllc/dply-demo-laravel-function');

    expect($site->sourceControlRepositoryUrl())
        ->toBe('https://github.com/shaferllc/dply-demo-laravel-function.git');
});

test('leaves an https remote untouched', function () {
    $url = 'https://github.com/shaferllc/dply-demo-laravel-function.git';

    expect(siteWithRepo($url)->sourceControlRepositoryUrl())->toBe($url);
});

test('leaves an ssh remote untouched', function () {
    $url = 'git@github.com:shaferllc/dply-demo-laravel-function.git';

    expect(siteWithRepo($url)->sourceControlRepositoryUrl())->toBe($url);
});

test('returns null when no repository is configured', function () {
    expect(siteWithRepo('')->sourceControlRepositoryUrl())->toBeNull();
});

test('the commits fetcher resolves a shorthand repo instead of asking for a URL', function () {
    // The reported bug: the Repository tab showed the repo in its header but
    // the commits panel answered "Add a Git repository URL in Deploy settings".
    $site = siteWithRepo('shaferllc/dply-demo-laravel-function');
    $user = User::query()->find($site->user_id);

    $result = app(SiteGitCommitsFetcher::class)->fetch($site, $user);

    expect($result['error'])->not->toBe('Add a Git repository URL in Deploy settings to list commits.');
    expect($result['provider'])->toBe('github');
});

test('commitWebUrl builds a provider link from a shorthand repo', function () {
    $site = siteWithRepo('shaferllc/dply-demo-laravel-function');

    expect($site->commitWebUrl('abc1234'))
        ->toBe('https://github.com/shaferllc/dply-demo-laravel-function/commit/abc1234');
});
