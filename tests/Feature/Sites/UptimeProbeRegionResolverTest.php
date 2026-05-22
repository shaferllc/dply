<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Services\Sites\UptimeProbeRegionResolver;
use Tests\TestCase;

class UptimeProbeRegionResolverTest extends TestCase
{
    public function test_it_maps_digitalocean_regions_to_the_nearest_probe(): void
    {
        $resolver = new UptimeProbeRegionResolver;

        $this->assertSame('us-east', $resolver->resolve('nyc1'));
        $this->assertSame('us-east', $resolver->resolve('nyc3'));
        $this->assertSame('us-east', $resolver->resolve('tor1'));
        $this->assertSame('us-west', $resolver->resolve('sfo3'));
        $this->assertSame('eu-amsterdam', $resolver->resolve('ams3'));
        $this->assertSame('eu-frankfurt', $resolver->resolve('fra1'));
        $this->assertSame('ap-sydney', $resolver->resolve('syd1'));
    }

    public function test_an_unknown_or_empty_region_falls_back_to_the_first_configured(): void
    {
        $resolver = new UptimeProbeRegionResolver;
        $first = (string) array_key_first(config('site_uptime.probe_regions'));

        $this->assertSame($first, $resolver->resolve('mars1'));
        $this->assertSame($first, $resolver->resolve(''));
        $this->assertSame($first, $resolver->resolve(null));
    }
}
