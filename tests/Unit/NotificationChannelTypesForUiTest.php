<?php

namespace Tests\Unit;

use App\Models\NotificationChannel;
use Tests\TestCase;

class NotificationChannelTypesForUiTest extends TestCase
{
    public function test_types_for_ui_intersects_with_config(): void
    {
        config(['notification_channels.enabled_types' => ['slack', 'webhook']]);

        $this->assertSame(['slack', 'webhook'], NotificationChannel::typesForUi());
    }

    public function test_types_for_ui_preserves_type_when_disabled_in_config(): void
    {
        config(['notification_channels.enabled_types' => ['slack']]);

        $types = NotificationChannel::typesForUi(NotificationChannel::TYPE_WEBHOOK);

        $this->assertContains(NotificationChannel::TYPE_WEBHOOK, $types);
        $this->assertContains(NotificationChannel::TYPE_SLACK, $types);
    }
}
