<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Actions\Servers\RecommendServerCreateSizes;
use Tests\TestCase;

class RecommendServerCreateSizesTest extends TestCase
{
    public function test_marks_tiny_database_node_as_too_small(): void
    {
        $result = RecommendServerCreateSizes::run('database', [
            ['value' => 'tiny', 'memory_mb' => 1024, 'vcpus' => 1, 'disk_gb' => 25],
            ['value' => 'balanced', 'memory_mb' => 4096, 'vcpus' => 2, 'disk_gb' => 80],
        ]);

        $this->assertSame('too_small', $result['tiny']['state']);
        $this->assertSame('good_starting_point', $result['balanced']['state']);
    }

    public function test_marks_large_plain_server_as_overkill(): void
    {
        $result = RecommendServerCreateSizes::run('plain', [
            ['value' => 'large', 'memory_mb' => 16384, 'vcpus' => 8, 'disk_gb' => 320],
        ]);

        $this->assertSame('overkill', $result['large']['state']);
    }
}
