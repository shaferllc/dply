<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteServerErrorPageBuilderTest;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Sites\SiteServerErrorPageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('render escapes site name and includes 500 copy', function () {
    $site = Site::factory()->create([
        'name' => 'Test & Co',
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);

    $html = (new SiteServerErrorPageBuilder)->render($site->fresh(['domains']));

    expect($html)
        ->toContain('Test &amp; Co')
        ->toContain('500 · Server error')
        ->toContain('app.example.test')
        ->toContain('noindex');
});

test('reference row is rendered only when the engine injects the token', function () {
    $site = Site::factory()->create();

    $withInjection = (new SiteServerErrorPageBuilder)->render($site->fresh(['domains']), true);
    $withoutInjection = (new SiteServerErrorPageBuilder)->render($site->fresh(['domains']), false);

    expect($withInjection)
        ->toContain('Reference')
        ->toContain(SiteServerErrorPageBuilder::REFERENCE_TOKEN);

    // Engines that only set the header must never leak the raw placeholder.
    expect($withoutInjection)->not->toContain(SiteServerErrorPageBuilder::REFERENCE_TOKEN);
});

test('managed error pages root lives under site env directory', function () {
    $site = Site::factory()->create();

    expect($site->managedErrorPagesRoot())->toEndWith('/.dply/errors');
});
