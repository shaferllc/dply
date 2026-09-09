<?php

namespace Tests\Unit\NotificationChannelTypesForUiTest;

use App\Models\NotificationChannel;

test('types for ui intersects with config', function () {
    config(['notification_channels.enabled_types' => ['slack', 'webhook']]);

    expect(NotificationChannel::typesForUi())->toBe(['slack', 'webhook']);
});

test('intercom is a known type and surfaces when enabled', function () {
    expect(NotificationChannel::types())->toContain(NotificationChannel::TYPE_INTERCOM);

    config(['notification_channels.enabled_types' => ['slack', 'intercom']]);

    expect(NotificationChannel::typesForUi())->toContain(NotificationChannel::TYPE_INTERCOM);
});

test('types for ui preserves type when disabled in config', function () {
    config(['notification_channels.enabled_types' => ['slack']]);

    $types = NotificationChannel::typesForUi(NotificationChannel::TYPE_WEBHOOK);

    expect($types)->toContain(NotificationChannel::TYPE_WEBHOOK);
    expect($types)->toContain(NotificationChannel::TYPE_SLACK);
});
