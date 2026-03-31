<?php

namespace Tests\Unit\Services;

use App\Services\Servers\ServerInventoryOsDetector;
use PHPUnit\Framework\TestCase;

class ServerInventoryOsDetectorTest extends TestCase
{
    public function test_maps_ubuntu_22_04(): void
    {
        $raw = <<<'EOS'
PRETTY_NAME="Ubuntu 22.04.5 LTS"
NAME="Ubuntu"
VERSION_ID="22.04"
VERSION="22.04.5 LTS (Jammy Jellyfish)"
ID=ubuntu
VERSION_CODENAME=jammy
EOS;

        $r = ServerInventoryOsDetector::fromOsRelease($raw);
        $this->assertSame('ubuntu-22-04', $r['key']);
        $this->assertStringContainsString('Ubuntu 22.04', (string) $r['pretty']);
    }

    public function test_maps_debian_12_bookworm(): void
    {
        $raw = <<<'EOS'
PRETTY_NAME="Debian GNU/Linux 12 (bookworm)"
NAME="Debian GNU/Linux"
VERSION_ID="12"
VERSION="12 (bookworm)"
ID=debian
VERSION_CODENAME=bookworm
EOS;

        $r = ServerInventoryOsDetector::fromOsRelease($raw);
        $this->assertSame('debian-12', $r['key']);
    }

    public function test_empty_returns_nulls(): void
    {
        $r = ServerInventoryOsDetector::fromOsRelease('');
        $this->assertNull($r['key']);
        $this->assertNull($r['pretty']);
    }
}
