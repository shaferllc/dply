<?php

namespace Tests\Unit\NotificationChannelTypesForUiTest;

use App\Models\NotificationChannel;

test('types for ui intersects with config', function () {
    config(['notification_channels.enabled_types' => ['slack', 'webhook']]);

    expect(NotificationChannel::typesForUi())->toBe(['slack', 'webhook']);
});

test('types for ui preserves type when disabled in config', function () {
    config(['notification_channels.enabled_types' => ['slack']]);

    $types = NotificationChannel::typesForUi(NotificationChannel::TYPE_WEBHOOK);

    expect($types)->toContain(NotificationChannel::TYPE_WEBHOOK);
    expect($types)->toContain(NotificationChannel::TYPE_SLACK);
});
