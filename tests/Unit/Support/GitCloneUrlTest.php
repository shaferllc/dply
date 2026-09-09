<?php

declare(strict_types=1);

namespace Tests\Unit\Support\GitCloneUrlTest;

use App\Support\GitCloneUrl;
use InvalidArgumentException;

/**
 * A clone is an outbound fetch the control plane performs against a URL the
 * caller supplied, and clone stderr comes back to that caller through the
 * deploy log and the detection error path. These are the shapes that turn the
 * repository field into a request-forgery primitive.
 */
test('owner/name shorthand expands to a GitHub HTTPS clone URL', function () {
    expect(GitCloneUrl::normalize('shaferllc/dply-demo-laravel-function'))
        ->toBe('https://github.com/shaferllc/dply-demo-laravel-function.git');
});

test('already-URL-shaped remotes are left untouched', function (string $url) {
    // normalize() only expands shorthand — deciding whether a target is
    // *allowed* is assertClonable()'s job, tested separately below.
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

it('accepts the ordinary public forms', function (string $url) {
    GitCloneUrl::assertClonable($url);
})->with([
    'laravel/laravel',
    'https://github.com/acme/checkout.git',
    'git@github.com:acme/checkout.git',
    'ssh://git@gitlab.com/acme/checkout.git',
])->throwsNoExceptions();

it('refuses schemes that read the platform\'s own disk or reach its network', function (string $url) {
    expect(fn () => GitCloneUrl::assertClonable($url))->toThrow(InvalidArgumentException::class);
})->with([
    'file:///srv/dply/.env',
    'git://10.0.0.5/internal.git',
    '/etc/passwd',
    '../../../etc',
]);

it('refuses literal addresses inside the platform network', function (string $url) {
    expect(fn () => GitCloneUrl::assertClonable($url))->toThrow(InvalidArgumentException::class);
})->with([
    'http://169.254.169.254/latest/meta-data',   // cloud metadata
    'https://127.0.0.1/x.git',
    'https://localhost:8080/x.git',
    'https://10.1.2.3/x.git',
    'https://192.168.1.10/x.git',
    'https://172.16.0.9/x.git',
    'https://100.64.5.5/x.git',                  // carrier-grade NAT
    'https://[::1]/x.git',
]);

it('still allows public addresses that merely look private', function () {
    // 100.128/10 is outside the CGNAT block and is ordinary public space.
    GitCloneUrl::assertClonable('https://100.128.5.5/x.git');
})->throwsNoExceptions();

it('lets an operator allowlist an internal git host', function () {
    expect(fn () => GitCloneUrl::assertClonable('https://192.168.5.5/x.git'))
        ->toThrow(InvalidArgumentException::class);

    GitCloneUrl::assertClonable('https://192.168.5.5/x.git', ['192.168.5.5']);
});

it('allowlists a whole domain with a leading dot', function () {
    GitCloneUrl::assertClonable('https://build.git.internal/x.git', ['.git.internal']);
})->throwsNoExceptions();

it('permits a bare filesystem path only when explicitly enabled', function () {
    // Off in production; the serverless checkout tests clone local fixtures.
    expect(fn () => GitCloneUrl::assertClonable('/tmp/fixture.git'))
        ->toThrow(InvalidArgumentException::class);

    GitCloneUrl::assertClonable('/tmp/fixture.git', [], true);
});

it('never permits file:// even when local paths are allowed', function () {
    expect(fn () => GitCloneUrl::assertClonable('file:///srv/secrets', [], true))
        ->toThrow(InvalidArgumentException::class);
});
