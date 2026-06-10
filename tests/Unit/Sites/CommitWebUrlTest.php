<?php

declare(strict_types=1);

namespace Tests\Unit\Sites\CommitWebUrlTest;

use App\Models\Site;

function urlFor(string $remote, ?string $sha = 'abc123def'): ?string
{
    $site = new Site(['git_repository_url' => $remote]);

    return $site->commitWebUrl($sha);
}

test('builds GitHub commit URLs from ssh and https remotes', function () {
    expect(urlFor('git@github.com:acme/app.git'))->toBe('https://github.com/acme/app/commit/abc123def');
    expect(urlFor('https://github.com/acme/app.git'))->toBe('https://github.com/acme/app/commit/abc123def');
    expect(urlFor('https://github.com/acme/app'))->toBe('https://github.com/acme/app/commit/abc123def');
});

test('uses the GitLab /-/commit path', function () {
    expect(urlFor('git@gitlab.com:acme/app.git'))->toBe('https://gitlab.com/acme/app/-/commit/abc123def');
    expect(urlFor('https://gitlab.com/group/sub/app.git'))->toBe('https://gitlab.com/group/sub/app/-/commit/abc123def');
});

test('uses the Bitbucket /commits path', function () {
    expect(urlFor('git@bitbucket.org:acme/app.git'))->toBe('https://bitbucket.org/acme/app/commits/abc123def');
});

test('handles an https remote with embedded credentials', function () {
    expect(urlFor('https://x-token-auth:token@github.com/acme/app.git'))
        ->toBe('https://github.com/acme/app/commit/abc123def');
});

test('returns null without a sha or a remote', function () {
    expect(urlFor('git@github.com:acme/app.git', null))->toBeNull();
    expect(urlFor('git@github.com:acme/app.git', ''))->toBeNull();
    expect(urlFor('', 'abc123'))->toBeNull();
});
