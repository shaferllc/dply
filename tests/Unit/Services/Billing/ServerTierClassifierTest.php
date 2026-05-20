<?php

namespace Tests\Unit\Services\Billing;

use App\Enums\ServerTier;
use App\Services\Billing\ServerTierClassifier;
use PHPUnit\Framework\TestCase;

class ServerTierClassifierTest extends TestCase
{
    private ServerTierClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ServerTierClassifier;
    }

    public function test_nulls_default_to_xs(): void
    {
        $this->assertSame(ServerTier::XS, $this->classifier->classify(null, null));
    }

    public function test_one_vcpu_and_two_gb_is_xs(): void
    {
        $this->assertSame(ServerTier::XS, $this->classifier->classify(1, 2048));
    }

    public function test_two_vcpu_four_gb_is_s(): void
    {
        $this->assertSame(ServerTier::S, $this->classifier->classify(2, 4096));
    }

    public function test_four_vcpu_eight_gb_is_m(): void
    {
        $this->assertSame(ServerTier::M, $this->classifier->classify(4, 8192));
    }

    public function test_eight_vcpu_sixteen_gb_is_l(): void
    {
        $this->assertSame(ServerTier::L, $this->classifier->classify(8, 16384));
    }

    public function test_above_l_is_xl(): void
    {
        $this->assertSame(ServerTier::XL, $this->classifier->classify(16, 32768));
    }

    public function test_takes_the_larger_axis(): void
    {
        // Tiny CPU, big RAM — still pays the bigger-tier price.
        $this->assertSame(ServerTier::L, $this->classifier->classify(1, 16384));
        // Huge CPU, modest RAM — same.
        $this->assertSame(ServerTier::XL, $this->classifier->classify(32, 4096));
    }

    public function test_three_vcpu_falls_into_m_bucket(): void
    {
        // 3 vCPU is unusual but exists (Hetzner CCX13, etc.); should land in M, not S.
        $this->assertSame(ServerTier::M, $this->classifier->classify(3, 4096));
    }

    public function test_boundary_just_over_l_ram_jumps_to_xl(): void
    {
        $this->assertSame(ServerTier::XL, $this->classifier->classify(4, 16385));
    }

    public function test_unknown_memory_with_known_cpu_uses_cpu(): void
    {
        $this->assertSame(ServerTier::L, $this->classifier->classify(8, null));
    }

    public function test_unknown_cpu_with_known_memory_uses_memory(): void
    {
        $this->assertSame(ServerTier::M, $this->classifier->classify(null, 8192));
    }
}
