<?php

namespace Tests\Unit;

use App\Serverless\Support\ProvisionerConfigReport;
use PHPUnit\Framework\TestCase;

class ProvisionerConfigReportTest extends TestCase
{
    public function test_lists_non_credential_keys_sorted(): void
    {
        $this->assertSame(
            ['a', 'project', 'z'],
            ProvisionerConfigReport::safeConfigKeys(['z' => 1, 'a' => 2, 'project' => []]),
        );
    }

    public function test_omits_credential_keys_but_adds_credentials_present(): void
    {
        $this->assertSame(
            ['credentials_present', 'project'],
            ProvisionerConfigReport::safeConfigKeys([
                'project' => ['id' => 1],
                'credentials' => ['api_token' => 'secret'],
            ]),
        );
    }

    public function test_empty_credentials_array_does_not_add_flag(): void
    {
        $this->assertSame(
            ['project'],
            ProvisionerConfigReport::safeConfigKeys([
                'project' => [],
                'credentials' => [],
            ]),
        );
    }
}
