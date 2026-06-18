<?php

namespace Tests\Unit\Services\Sites\ReleaseEnvLinkCheckerParseTest;

use App\Services\Sites\ReleaseEnvLinkChecker;
use App\Services\SshConnectionFactory;

function checker(): ReleaseEnvLinkChecker
{
    // parse() is pure; the SSH factory is never touched.
    return new ReleaseEnvLinkChecker(
        app(SshConnectionFactory::class),
    );
}

test('all releases symlinked to the canonical file report no drift', function () {
    $raw = <<<'OUT'
        CANON /home/dply/dply/shared/.env
        20260610120000 OK
        20260609120000 OK
        20260608120000 OK
        OUT;

    $result = checker()->parse($raw);

    expect($result['applicable'])->toBeTrue();
    expect($result['canonical'])->toBe('/home/dply/dply/shared/.env');
    expect($result['checked'])->toBe(3);
    expect($result['drifted'])->toBe([]);
});

test('a release carrying a real .env file is flagged as drift', function () {
    $raw = <<<'OUT'
        CANON /home/dply/dply/shared/.env
        20260610120000 OK
        20260601120000 REALFILE
        OUT;

    $result = checker()->parse($raw);

    expect($result['checked'])->toBe(2);
    expect($result['drifted'])->toBe([
        ['release' => '20260601120000', 'kind' => 'real_file', 'target' => null],
    ]);
});

test('a symlink pointing somewhere else captures the wrong target', function () {
    $raw = <<<'OUT'
        CANON /home/dply/dply/shared/.env
        20260610120000 OTHER:/home/dply/dply/releases/20260610120000/shared/.env
        OUT;

    $result = checker()->parse($raw);

    expect($result['drifted'])->toBe([
        [
            'release' => '20260610120000',
            'kind' => 'wrong_target',
            'target' => '/home/dply/dply/releases/20260610120000/shared/.env',
        ],
    ]);
});

test('a release with no .env at all is flagged as missing', function () {
    $raw = <<<'OUT'
        CANON /home/dply/dply/shared/.env
        20260610120000 MISSING
        OUT;

    $result = checker()->parse($raw);

    expect($result['drifted'][0]['kind'])->toBe('missing');
});

test('mixed output separates healthy from drifted releases', function () {
    $raw = <<<'OUT'
        CANON /home/dply/dply/shared/.env
        r1 OK
        r2 REALFILE
        r3 OK
        r4 MISSING
        OUT;

    $result = checker()->parse($raw);

    expect($result['checked'])->toBe(4);
    expect($result['drifted'])->toHaveCount(2);
    expect(collect($result['drifted'])->pluck('release')->all())->toBe(['r2', 'r4']);
});

test('an empty target on a broken symlink parses to null', function () {
    $raw = "CANON /home/dply/dply/shared/.env\nr1 OTHER:";

    $result = checker()->parse($raw);

    expect($result['drifted'][0])->toBe(['release' => 'r1', 'kind' => 'wrong_target', 'target' => null]);
});
