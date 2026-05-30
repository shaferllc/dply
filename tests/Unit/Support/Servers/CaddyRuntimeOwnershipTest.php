<?php

namespace Tests\Unit\Support\Servers\CaddyRuntimeOwnershipTest;

use App\Support\Servers\CaddyRuntimeOwnership;

test('runtime ownership shell fixes caddy log dir ownership', function () {
    $shell = CaddyRuntimeOwnership::shell();

    expect($shell)->toContain('chown -R caddy:caddy /var/lib/caddy /var/log/caddy');
});

test('validate command runs as caddy user', function () {
    expect(CaddyRuntimeOwnership::validateCommand())->toBe(
        'sudo -u caddy caddy validate --config /etc/caddy/Caddyfile',
    );
});
