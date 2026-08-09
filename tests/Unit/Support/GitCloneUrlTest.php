<?php

declare(strict_types=1);

namespace Tests\Unit\Support\GitCloneUrlTest;

use App\Support\GitCloneUrl;

test('owner/name shorthand expands to a GitHub HTTPS clone URL', function () {
    expect(GitCloneUrl::normalize('shaferllc/dply-demo-laravel-function'))
        ->toBe('https://github.com/shaferllc/dply-demo-laravel-function.git');
});

test('already-URL-shaped remotes are left untouched', function (string $url) {
    expect(GitCloneUrl::normalize($url))->toBe($url);
})->with([
    'https://github.com/acme/api.git',
    'http://github.com/acme/api.git',
    'git://github.com/acme/api.git',
    'ssh://git@github.com/acme/api.git',
    'git@github.com:acme/api.git',
    'file:///tmp/origin.git',
]);

test('blank and non-shorthand values pass through', function () {
    expect(GitCloneUrl::normalize(''))->toBe('');
    expect(GitCloneUrl::normalize('   '))->toBe('');
    expect(GitCloneUrl::normalize('not-a-repo'))->toBe('not-a-repo');
});
