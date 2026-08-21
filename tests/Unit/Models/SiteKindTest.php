<?php

declare(strict_types=1);

use App\Models\Site;

/**
 * One Site model, four products. `siteKind()` is what API payloads expose so
 * clients (`dply sites`) don't have to re-derive it from the columns.
 */
it('names each product from the attributes that define it', function () {
    expect((new Site)->siteKind())->toBe('vm');

    expect((new Site(['edge_backend' => 'cloudflare']))->siteKind())->toBe('edge');

    expect((new Site(['container_backend' => 'do_app_platform']))->siteKind())->toBe('cloud');
    expect((new Site(['container_backend' => 'dply_cloud']))->siteKind())->toBe('cloud');

    $function = new Site;
    $function->meta = ['runtime_profile' => 'digitalocean_functions_web'];
    expect($function->siteKind())->toBe('serverless');
});

it('treats an empty backend string as not-that-product', function () {
    // Factories and PHP sites leave these unset; '' must not read as a product.
    expect((new Site(['edge_backend' => '', 'container_backend' => '']))->siteKind())->toBe('vm');
});

it('calls a container app that runs a function runtime serverless', function () {
    // Functions are containers underneath on some backends — the runtime wins,
    // because that is what the serverless API lists on.
    $site = new Site(['container_backend' => 'dply_cloud']);
    $site->meta = ['runtime_profile' => 'aws_lambda_bref_web'];

    expect($site->siteKind())->toBe('serverless');
});
