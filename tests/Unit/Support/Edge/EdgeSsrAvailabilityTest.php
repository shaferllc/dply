<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Modules\Edge\Support\EdgeSsrAvailability;

beforeEach(function () {
    config([
        'edge.fake.enabled' => false,
        'edge.cloudflare.account_id' => '',
        'edge.cloudflare.api_token' => '',
        'edge.cloudflare.dispatch_namespace_name' => 'dply-edge-ssr',
        'edge.cloudflare.dispatch_namespace_id' => '',
    ]);
});

test('ssr is unavailable without platform cloudflare credentials', function () {
    expect(EdgeSsrAvailability::isAvailable())->toBeFalse()
        ->and(EdgeSsrAvailability::unavailableReason())->not->toBeNull();
});

test('ssr is available when account and api token are set even without namespace id', function () {
    config([
        'edge.cloudflare.account_id' => 'acct_123',
        'edge.cloudflare.api_token' => 'token_abc',
        'edge.cloudflare.dispatch_namespace_id' => '',
    ]);

    expect(EdgeSsrAvailability::isAvailable())->toBeTrue()
        ->and(EdgeSsrAvailability::unavailableReason())->toBeNull();
});

test('ssr is available in fake edge mode without credentials', function () {
    config([
        'edge.fake.enabled' => true,
        'edge.fake.allowed_environments' => ['local', 'testing', app()->environment()],
    ]);

    expect(EdgeSsrAvailability::isAvailable())->toBeTrue();
});
