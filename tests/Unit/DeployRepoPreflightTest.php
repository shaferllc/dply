<?php

declare(strict_types=1);

namespace Tests\Unit\DeployRepoPreflightTest;

use App\Models\Site;
use App\Modules\Deploy\Services\DeployRepoPreflight;
use App\Modules\Remediations\Services\RemediationCatalog;
use Symfony\Component\Process\Process;

/**
 * Exercises the preflight against a REAL local bare repository (file:// URLs)
 * so the git behaviour is genuine, with no network and no credentials.
 */
function makeBareRepoWithBranch(string $branch): string
{
    $base = sys_get_temp_dir().'/dply-preflight-test-'.uniqid();
    $bare = $base.'/origin.git';
    $work = $base.'/work';
    mkdir($bare, 0755, true);
    mkdir($work, 0755, true);

    foreach ([
        ['git', 'init', '--bare', '--initial-branch='.$branch, $bare],
        ['git', 'init', '--initial-branch='.$branch, $work],
        ['git', '-C', $work, '-c', 'user.email=t@t.t', '-c', 'user.name=t', 'commit', '--allow-empty', '-m', 'init'],
        ['git', '-C', $work, 'push', $bare, $branch],
    ] as $cmd) {
        $p = new Process($cmd);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException('fixture setup failed: '.$p->getErrorOutput());
        }
    }

    return $bare;
}

test('a site with no repository url is skipped', function () {
    $site = new Site(['git_repository_url' => '', 'git_branch' => 'main']);

    expect(app(DeployRepoPreflight::class)->check($site))->toBeNull();
});

test('a reachable repo with an existing branch passes', function () {
    $bare = makeBareRepoWithBranch('main');
    $site = new Site(['git_repository_url' => 'file://'.$bare, 'git_branch' => 'main']);

    expect(app(DeployRepoPreflight::class)->check($site))->toBeNull();
});

test('a missing branch fails fast with a message the catalog recognizes', function () {
    $bare = makeBareRepoWithBranch('main');
    $site = new Site(['git_repository_url' => 'file://'.$bare, 'git_branch' => 'master']);

    $error = app(DeployRepoPreflight::class)->check($site);

    expect($error)->not->toBeNull();
    expect($error)->toContain("'master' was not found on the remote repository");
    expect(app(RemediationCatalog::class)->match($error)['code'] ?? null)->toBe('git_branch_missing');
});

test('a nonexistent repository fails fast with git\'s own error', function () {
    $site = new Site([
        'git_repository_url' => 'file:///nonexistent/dply-preflight-'.uniqid().'.git',
        'git_branch' => 'main',
    ]);

    $error = app(DeployRepoPreflight::class)->check($site);

    expect($error)->not->toBeNull();
    expect($error)->toContain('Repository preflight failed');
});

test('owner/repo shorthand is expanded instead of treated as a local path', function () {
    // Without normalization, git ls-remote would interpret "owner/repo" as a
    // filesystem path and fail with "does not appear to be a git repository".
    $site = new Site([
        'git_repository_url' => 'shaferllc/dply-preflight-missing-'.uniqid(),
        'git_branch' => 'main',
    ]);

    $error = app(DeployRepoPreflight::class)->check($site);

    // Network-shaped failures skip the preflight (null). A definitive host
    // rejection still blocks — either way we must not see the local-path error.
    if ($error !== null) {
        expect($error)->not->toContain('does not appear to be a git repository');
        expect($error)->toContain('github.com');
    }
});
