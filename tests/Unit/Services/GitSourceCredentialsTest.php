<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GitSourceCredentialsTest;

use App\Models\SiteGitSource;
use App\Modules\SourceControl\Contracts\GitIdentity;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use App\Modules\WordPress\Services\GitSourceCredentials;
use Mockery;

afterEach(function () {
    Mockery::close();
});

test('ssh repo urls are normalized to https for token auth', function (string $in, string $out) {
    expect(GitSourceCredentials::httpsUrl($in))->toBe($out);
})->with([
    'scp form' => ['git@github.com:acme/theme.git', 'https://github.com/acme/theme.git'],
    'ssh scheme' => ['ssh://git@gitlab.com/acme/theme.git', 'https://gitlab.com/acme/theme.git'],
    'already https' => ['https://github.com/acme/theme.git', 'https://github.com/acme/theme.git'],
]);

/**
 * The provider username (x-access-token / oauth2 / x-token-auth) is decided in
 * exactly one place — SourceControlRepositoryBrowser. Git and Composer auth
 * must be derived from it, not re-decided here.
 */
test('git and composer credentials derive from the authenticated clone url', function () {
    $identity = Mockery::mock(GitIdentity::class);

    $browser = Mockery::mock(SourceControlRepositoryBrowser::class);
    $browser->shouldReceive('authenticatedCloneUrl')
        ->with($identity, 'https://github.com/acme/theme.git')
        ->andReturn('https://x-access-token:tok%2Fen@github.com/acme/theme.git');

    $credentials = new GitSourceCredentials(Mockery::mock(GitIdentityResolver::class), $browser);
    $source = new SiteGitSource(['repository_url' => 'git@github.com:acme/theme.git']);

    $env = $credentials->gitAuthEnv($source, $identity);
    expect($env['GIT_CONFIG_KEY_0'])->toBe('http.https://github.com/.extraHeader')
        ->and($env['GIT_CONFIG_VALUE_0'])->toBe('Authorization: Basic '.base64_encode('x-access-token:tok/en'));

    $auth = json_decode((string) $credentials->composerAuth($source, $identity), true);
    expect($auth['http-basic']['github.com'])->toBe(['username' => 'x-access-token', 'password' => 'tok/en']);
});

test('a pasted-url source has no identity', function () {
    $credentials = new GitSourceCredentials(
        Mockery::mock(GitIdentityResolver::class),
        Mockery::mock(SourceControlRepositoryBrowser::class),
    );

    expect($credentials->identityFor(new SiteGitSource(['repository_url' => 'git@github.com:acme/theme.git'])))->toBeNull();
});

/**
 * A connected source whose account vanished must fail loudly. Falling back to
 * an anonymous clone would surface as a baffling "repository not found".
 */
test('a connected source whose connector is gone throws', function () {
    $credentials = new GitSourceCredentials(
        Mockery::mock(GitIdentityResolver::class),
        Mockery::mock(SourceControlRepositoryBrowser::class),
    );

    $source = new SiteGitSource([
        'repository_url' => 'https://github.com/acme/theme.git',
        'source_control_account_id' => 'acct-1',
        'connected_by_user_id' => null,
    ]);

    $credentials->identityFor($source);
})->throws(\RuntimeException::class);
