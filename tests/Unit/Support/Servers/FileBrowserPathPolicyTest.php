<?php

declare(strict_types=1);

use App\Support\Servers\FileBrowserPathPolicy;

test('resolveLinkTarget resolves absolute symlink targets', function () {
    expect(FileBrowserPathPolicy::resolveLinkTarget(
        '/home/dply/site.com/current',
        '/home/dply/site.com/releases/20260707154231',
    ))->toBe('/home/dply/site.com/releases/20260707154231');
});

test('resolveLinkTarget resolves relative symlink targets from the link parent', function () {
    expect(FileBrowserPathPolicy::resolveLinkTarget(
        '/home/dply/site.com/current',
        'releases/20260707154231',
    ))->toBe('/home/dply/site.com/releases/20260707154231');
});

test('resolveLinkTarget collapses dot segments', function () {
    expect(FileBrowserPathPolicy::resolveLinkTarget(
        '/home/dply/site.com/shared/storage',
        '../releases/20260707154231/storage/app',
    ))->toBe('/home/dply/site.com/releases/20260707154231/storage/app');
});

test('canonicalize collapses parent segments at root to slash', function () {
    expect(FileBrowserPathPolicy::canonicalize('/../../etc'))->toBe('/etc');
});

test('willBeOverwrittenOnDeploy flags atomic releases and current but not shared', function () {
    $root = '/home/dply/site.com';

    expect(FileBrowserPathPolicy::willBeOverwrittenOnDeploy($root.'/releases/2026-05-11/app/helpers.php', $root, true))->toBeTrue()
        ->and(FileBrowserPathPolicy::willBeOverwrittenOnDeploy($root.'/current/app/helpers.php', $root, true))->toBeTrue()
        ->and(FileBrowserPathPolicy::willBeOverwrittenOnDeploy($root.'/shared/.env', $root, true))->toBeFalse()
        ->and(FileBrowserPathPolicy::willBeOverwrittenOnDeploy($root.'/hooks/after_activate.sh', $root, true))->toBeFalse();
});

test('willBeOverwrittenOnDeploy flags the simple checkout except shared', function () {
    $root = '/home/dply/dplyio';

    expect(FileBrowserPathPolicy::willBeOverwrittenOnDeploy($root.'/app/helpers.php', $root, false))->toBeTrue()
        ->and(FileBrowserPathPolicy::willBeOverwrittenOnDeploy($root.'/.env', $root, false))->toBeTrue()
        ->and(FileBrowserPathPolicy::willBeOverwrittenOnDeploy($root.'/shared/.env', $root, false))->toBeFalse()
        ->and(FileBrowserPathPolicy::willBeOverwrittenOnDeploy('/etc/hosts', $root, false))->toBeFalse();
});
