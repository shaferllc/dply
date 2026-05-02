<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\HostnameValidator;
use Tests\TestCase;

class HostnameValidatorTest extends TestCase
{
    public function test_it_accepts_a_valid_domain_name(): void
    {
        $this->assertTrue(HostnameValidator::isValid('app.example.com'));
    }

    public function test_it_rejects_a_single_label_hostname(): void
    {
        $this->assertFalse(HostnameValidator::isValid('test'));
    }

    public function test_it_rejects_invalid_hostname_characters(): void
    {
        $this->assertFalse(HostnameValidator::isValid('bad_host.example.com'));
    }

    public function test_it_rejects_ip_addresses(): void
    {
        $this->assertFalse(HostnameValidator::isValid('127.0.0.1'));
    }
}
