<?php

declare(strict_types=1);

namespace Tests\Unit\SiteServerlessKeepWarmTest;

use App\Models\Site;

function siteWithServerless(array $serverless): Site
{
    $site = new Site;
    $site->meta = ['serverless' => $serverless];

    return $site;
}

test('missing keep_warm stays off so existing functions are not surprised', function () {
    $site = siteWithServerless([]);

    expect($site->serverlessKeepWarmEnabled())->toBeFalse();
    expect($site->serverlessWantsKeepWarmPing())->toBeFalse();
});

test('keep_warm on requests a dedicated ping', function () {
    $site = siteWithServerless(['keep_warm' => true]);

    expect($site->serverlessKeepWarmEnabled())->toBeTrue();
    expect($site->serverlessWantsKeepWarmPing())->toBeTrue();
});

test('background processing suppresses the extra keep-warm ping', function () {
    $site = siteWithServerless([
        'keep_warm' => true,
        'background_enabled' => true,
    ]);

    expect($site->serverlessKeepWarmEnabled())->toBeTrue();
    expect($site->serverlessBackgroundProcessingEnabled())->toBeTrue();
    expect($site->serverlessWantsKeepWarmPing())->toBeFalse();
});
