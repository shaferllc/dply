<?php

namespace Tests\Unit\Support\ServerSystemdServiceNotificationKeysTest;

use App\Support\ServerSystemdServiceNotificationKeys;

it('builds keys under eighty chars for typical units', function () {
    $key = ServerSystemdServiceNotificationKeys::eventKey('nginx.service', 'stopped');
    expect(strlen($key))->toBeLessThanOrEqual(80);
    expect($key)->toBe('server.systemd.u.nginx.stopped');
});

it('validates dynamic event keys', function () {
    expect(ServerSystemdServiceNotificationKeys::isValidDynamicEventKey('server.systemd.u.nginx.stopped'))->toBeTrue();
    expect(ServerSystemdServiceNotificationKeys::isValidDynamicEventKey('server.ssh_login'))->toBeFalse();
});
