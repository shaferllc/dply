<?php

namespace Tests\Unit\Services\SiteCloneStrategyResolverTest;

use App\Models\Site;
use App\Services\Sites\Clone\ContainerSiteCloneStrategy;
use App\Services\Sites\Clone\SiteCloneStrategyResolver;
use App\Services\Sites\Clone\VmSiteCloneStrategy;

test('selects vm strategy for vm profile', function () {
    $resolver = app(SiteCloneStrategyResolver::class);
    $site = new Site(['meta' => ['runtime_profile' => 'vm_web']]);

    expect($resolver->for($site))->toBeInstanceOf(VmSiteCloneStrategy::class);
});

test('selects vm strategy for leftover functions profiles', function (string $profile) {
    $resolver = app(SiteCloneStrategyResolver::class);
    $site = new Site(['meta' => ['runtime_profile' => $profile]]);

    expect($resolver->for($site))->toBeInstanceOf(VmSiteCloneStrategy::class);
})->with([
    'digitalocean_functions_web',
    'aws_lambda_bref_web',
]);

test('selects container strategy for docker', function () {
    $resolver = app(SiteCloneStrategyResolver::class);
    $site = new Site(['meta' => ['runtime_profile' => 'docker_web']]);

    expect($resolver->for($site))->toBeInstanceOf(ContainerSiteCloneStrategy::class);
});

test('selects container strategy for kubernetes', function () {
    $resolver = app(SiteCloneStrategyResolver::class);
    $site = new Site(['meta' => ['runtime_profile' => 'kubernetes_web']]);

    expect($resolver->for($site))->toBeInstanceOf(ContainerSiteCloneStrategy::class);
});
