<?php

declare(strict_types=1);

namespace Tests\Unit\Support\EdgeSiteNotificationKeysTest;

use App\Models\Site;
use App\Support\EdgeSiteNotificationKeys;
use App\Support\NotificationSubscriptionRules;

test('edge site notification keys are loaded from config', function () {
    $keys = EdgeSiteNotificationKeys::eventKeys();

    expect($keys)->toContain('edge.deploy.succeeded')
        ->and($keys)->toContain('edge.rum.breach')
        ->and($keys)->toContain('edge.deploy.failed');
});

test('edge events subscribe on the site model', function () {
    expect(NotificationSubscriptionRules::subscribableClassForEvent('edge.rum.breach'))->toBe(Site::class)
        ->and(NotificationSubscriptionRules::subscribableClassForEvent('edge.deploy.failed'))->toBe(Site::class)
        ->and(NotificationSubscriptionRules::eventAppliesTo('edge.domain.verified', Site::class))->toBeTrue();
});
