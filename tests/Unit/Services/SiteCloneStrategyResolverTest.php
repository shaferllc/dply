<?php

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Services\Sites\Clone\ContainerSiteCloneStrategy;
use App\Services\Sites\Clone\ServerlessSiteCloneStrategy;
use App\Services\Sites\Clone\SiteCloneStrategyResolver;
use App\Services\Sites\Clone\VmSiteCloneStrategy;
use Tests\TestCase;

class SiteCloneStrategyResolverTest extends TestCase
{
    public function test_selects_vm_strategy_for_vm_profile(): void
    {
        $resolver = app(SiteCloneStrategyResolver::class);
        $site = new Site(['meta' => ['runtime_profile' => 'vm_web']]);

        $this->assertInstanceOf(VmSiteCloneStrategy::class, $resolver->for($site));
    }

    public function test_selects_serverless_strategy_for_functions_runtime(): void
    {
        $resolver = app(SiteCloneStrategyResolver::class);
        $site = new Site(['meta' => ['runtime_profile' => 'digitalocean_functions_web']]);

        $this->assertInstanceOf(ServerlessSiteCloneStrategy::class, $resolver->for($site));
    }

    public function test_selects_serverless_strategy_for_aws_lambda_profile(): void
    {
        $resolver = app(SiteCloneStrategyResolver::class);
        $site = new Site(['meta' => ['runtime_profile' => 'aws_lambda_bref_web']]);

        $this->assertInstanceOf(ServerlessSiteCloneStrategy::class, $resolver->for($site));
    }

    public function test_selects_container_strategy_for_docker(): void
    {
        $resolver = app(SiteCloneStrategyResolver::class);
        $site = new Site(['meta' => ['runtime_profile' => 'docker_web']]);

        $this->assertInstanceOf(ContainerSiteCloneStrategy::class, $resolver->for($site));
    }

    public function test_selects_container_strategy_for_kubernetes(): void
    {
        $resolver = app(SiteCloneStrategyResolver::class);
        $site = new Site(['meta' => ['runtime_profile' => 'kubernetes_web']]);

        $this->assertInstanceOf(ContainerSiteCloneStrategy::class, $resolver->for($site));
    }
}
