<?php

namespace Tests\Unit\Services;

use App\Services\Sites\AtomicDeployHealthChecker;
use PHPUnit\Framework\TestCase;

class AtomicDeployHealthCheckerTest extends TestCase
{
    public function test_normalize_path(): void
    {
        $c = new AtomicDeployHealthChecker;

        $this->assertSame('/', $c->normalizePath(''));
        $this->assertSame('/health', $c->normalizePath('/health'));
        $this->assertSame('/health', $c->normalizePath('health'));
        $this->assertSame('/v1/up', $c->normalizePath('/v1/up'));
    }
}
