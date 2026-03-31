<?php

namespace Tests\Unit\Support;

use App\Support\ServerSystemdServiceNotificationKeys;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServerSystemdServiceNotificationKeysTest extends TestCase
{
    #[Test]
    public function it_builds_keys_under_eighty_chars_for_typical_units(): void
    {
        $key = ServerSystemdServiceNotificationKeys::eventKey('nginx.service', 'stopped');
        $this->assertLessThanOrEqual(80, strlen($key));
        $this->assertSame('server.systemd.u.nginx.stopped', $key);
    }

    #[Test]
    public function it_validates_dynamic_event_keys(): void
    {
        $this->assertTrue(ServerSystemdServiceNotificationKeys::isValidDynamicEventKey('server.systemd.u.nginx.stopped'));
        $this->assertFalse(ServerSystemdServiceNotificationKeys::isValidDynamicEventKey('server.ssh_login'));
    }
}
