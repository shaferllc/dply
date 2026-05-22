<?php

namespace Tests\Unit\Services\SiteSuspendedPageBuilderTest;

use App\Models\Site;
use App\Services\Sites\SiteSuspendedPageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('render escapes site name and includes optional message from meta', function () {
    $site = Site::factory()->create([
        'name' => 'Test & Co',
        'meta' => ['suspended_message' => 'Contact <support@example.com>'],
    ]);

    $html = (new SiteSuspendedPageBuilder)->render($site);

    $this->assertStringContainsString('Test &amp; Co', $html);
    $this->assertStringContainsString('Contact &lt;support@example.com&gt;', $html);
    $this->assertStringContainsString('noindex', $html);
});

test('suspended public message prefers meta over legacy column', function () {
    $site = Site::factory()->create([
        'suspended_reason' => 'Legacy column',
        'meta' => ['suspended_message' => 'From meta'],
    ]);

    expect($site->suspendedPublicMessage())->toBe('From meta');
});

test('suspended public message falls back to legacy column', function () {
    $site = Site::factory()->create([
        'suspended_reason' => 'Legacy only',
        'meta' => [],
    ]);

    expect($site->suspendedPublicMessage())->toBe('Legacy only');
});
