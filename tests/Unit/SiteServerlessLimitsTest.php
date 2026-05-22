<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Site;
use PHPUnit\Framework\TestCase;

class SiteServerlessLimitsTest extends TestCase
{
    private function siteWithLimits(mixed $limits): Site
    {
        $site = new Site;
        $site->meta = ['serverless' => ['limits' => $limits]];

        return $site;
    }

    public function test_it_returns_platform_defaults_when_no_limits_are_stored(): void
    {
        $site = new Site;
        $site->meta = [];

        $this->assertSame([
            'memory' => Site::SERVERLESS_DEFAULT_MEMORY_MB,
            'timeout' => Site::SERVERLESS_DEFAULT_TIMEOUT_MS,
            'concurrency' => Site::SERVERLESS_DEFAULT_CONCURRENCY,
        ], $site->serverlessLimits());
    }

    public function test_it_passes_through_valid_stored_limits(): void
    {
        $limits = $this->siteWithLimits(['memory' => 1024, 'timeout' => 120000, 'concurrency' => 8])
            ->serverlessLimits();

        $this->assertSame(['memory' => 1024, 'timeout' => 120000, 'concurrency' => 8], $limits);
    }

    public function test_it_falls_back_to_default_memory_for_an_unsupported_value(): void
    {
        $this->assertSame(
            Site::SERVERLESS_DEFAULT_MEMORY_MB,
            $this->siteWithLimits(['memory' => 999])->serverlessLimits()['memory'],
        );
    }

    public function test_it_clamps_timeout_into_the_allowed_range(): void
    {
        $this->assertSame(
            Site::SERVERLESS_MAX_TIMEOUT_MS,
            $this->siteWithLimits(['timeout' => 9_000_000])->serverlessLimits()['timeout'],
        );
        $this->assertSame(
            Site::SERVERLESS_MIN_TIMEOUT_MS,
            $this->siteWithLimits(['timeout' => 1])->serverlessLimits()['timeout'],
        );
    }

    public function test_it_clamps_concurrency_into_the_allowed_range(): void
    {
        $this->assertSame(
            Site::SERVERLESS_MAX_CONCURRENCY,
            $this->siteWithLimits(['concurrency' => 999])->serverlessLimits()['concurrency'],
        );
        $this->assertSame(
            1,
            $this->siteWithLimits(['concurrency' => 0])->serverlessLimits()['concurrency'],
        );
    }
}
