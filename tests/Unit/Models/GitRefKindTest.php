<?php

declare(strict_types=1);

namespace Tests\Unit\Models\GitRefKindTest;

use App\Models\Site;

/**
 * dply's own dply-app-2 sat on a 9-hour-old commit while every sync_peer deploy
 * reported success: meta.git_ref_kind was 'commit' but git_branch was "main",
 * so the deployer took the commit path — fetch (FETCH_HEAD only), checkout the
 * unchanged local branch, return before the pull that would fast-forward it.
 */
test('a commit pin that is not a sha falls back to branch', function () {
    $site = new Site(['git_branch' => 'main']);
    $site->meta = ['git_ref_kind' => 'commit'];

    expect($site->gitRefKind())->toBe('branch');
});

test('a real sha is still treated as a commit', function () {
    $site = new Site(['git_branch' => '633755a']);
    $site->meta = ['git_ref_kind' => 'commit'];
    expect($site->gitRefKind())->toBe('commit');

    $full = new Site(['git_branch' => 'fe823bcd6a1b2c3d4e5f60718293a4b5c6d7e8f9']);
    $full->meta = ['git_ref_kind' => 'commit'];
    expect($full->gitRefKind())->toBe('commit');
});

test('branch and tag pins are untouched', function () {
    $branch = new Site(['git_branch' => 'main']);
    $branch->meta = ['git_ref_kind' => 'branch'];
    expect($branch->gitRefKind())->toBe('branch');

    $tag = new Site(['git_branch' => 'v1.2.3']);
    $tag->meta = ['git_ref_kind' => 'tag'];
    expect($tag->gitRefKind())->toBe('tag');
});

test('an unset kind defaults to branch', function () {
    expect((new Site(['git_branch' => 'main']))->gitRefKind())->toBe('branch');
});
