<?php

namespace Tests\Unit\Services\ServerInventoryOsDetectorTest;

use App\Services\Servers\ServerInventoryOsDetector;

test('maps ubuntu 22 04', function () {
    $raw = <<<'EOS'
PRETTY_NAME="Ubuntu 22.04.5 LTS"
NAME="Ubuntu"
VERSION_ID="22.04"
VERSION="22.04.5 LTS (Jammy Jellyfish)"
ID=ubuntu
VERSION_CODENAME=jammy
EOS;

    $r = ServerInventoryOsDetector::fromOsRelease($raw);
    expect($r['key'])->toBe('ubuntu-22-04');
    $this->assertStringContainsString('Ubuntu 22.04', (string) $r['pretty']);
});

test('maps debian 12 bookworm', function () {
    $raw = <<<'EOS'
PRETTY_NAME="Debian GNU/Linux 12 (bookworm)"
NAME="Debian GNU/Linux"
VERSION_ID="12"
VERSION="12 (bookworm)"
ID=debian
VERSION_CODENAME=bookworm
EOS;

    $r = ServerInventoryOsDetector::fromOsRelease($raw);
    expect($r['key'])->toBe('debian-12');
});

test('empty returns nulls', function () {
    $r = ServerInventoryOsDetector::fromOsRelease('');
    expect($r['key'])->toBeNull();
    expect($r['pretty'])->toBeNull();
});
