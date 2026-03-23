<?php

declare(strict_types=1);

namespace Dply\Core\Tests;

use Dply\Core\Net\IpAllowList;
use PHPUnit\Framework\TestCase;

final class IpAllowListTest extends TestCase
{
    public function test_exact_match(): void
    {
        $this->assertTrue(IpAllowList::contains('203.0.113.10', ['203.0.113.10']));
        $this->assertFalse(IpAllowList::contains('203.0.113.11', ['203.0.113.10']));
    }

    public function test_cidr_match(): void
    {
        $this->assertTrue(IpAllowList::contains('10.0.0.5', ['10.0.0.0/24']));
        $this->assertFalse(IpAllowList::contains('10.0.1.5', ['10.0.0.0/24']));
    }

    public function test_skips_empty_entries(): void
    {
        $this->assertTrue(IpAllowList::contains('1.2.3.4', ['', '  ', '1.2.3.4']));
    }
}
