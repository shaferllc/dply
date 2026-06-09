<?php

declare(strict_types=1);

use App\Services\Servers\ServerAptLockBash;

test('apt lock bash detects lock failures in output', function (): void {
    $output = <<<'TXT'
E: Could not get lock /var/lib/dpkg/lock-frontend. It is held by process 5873 (apt-get)
E: Unable to acquire the dpkg frontend lock (/var/lib/dpkg/lock-frontend), is another process using it?
TXT;

    expect(ServerAptLockBash::outputLooksLikeAptLockFailure($output))->toBeTrue()
        ->and(ServerAptLockBash::outputLooksLikeAptLockFailure('all good', 100))->toBeTrue()
        ->and(ServerAptLockBash::outputLooksLikeAptLockFailure('all good', 0))->toBeFalse();
});

test('apt lock bash wraps manage scripts that call apt-get', function (): void {
    $wrapped = ServerAptLockBash::wrapManageScript("set -e\napt-get update -y\n");

    expect($wrapped)->toContain('dply_wait_for_apt_locks')
        ->and($wrapped)->toContain('apt-get update -y');
});

test('apt lock bash skips wrap for non-apt scripts', function (): void {
    $script = "echo hello\n";

    expect(ServerAptLockBash::wrapManageScript($script))->toBe($script);
});
