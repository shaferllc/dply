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
