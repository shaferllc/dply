<?php

declare(strict_types=1);

use App\Models\Site;

it('names leftover product columns as ordinary VM sites', function () {
    expect((new Site)->siteKind())->toBe('vm');

    expect((new Site(['edge_backend' => 'cloudflare']))->siteKind())->toBe('vm');

    expect((new Site(['container_backend' => 'do_app_platform']))->siteKind())->toBe('vm');
    expect((new Site(['container_backend' => 'dply_cloud']))->siteKind())->toBe('vm');

    $function = new Site;
    $function->meta = ['runtime_profile' => 'digitalocean_functions_web'];
    expect($function->siteKind())->toBe('vm');
});

it('treats an empty backend string as a VM site', function () {
    expect((new Site(['edge_backend' => '', 'container_backend' => '']))->siteKind())->toBe('vm');
});
