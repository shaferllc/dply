<?php

declare(strict_types=1);

use App\Support\Servers\EnvoyAdminScript;

test('envoy admin script includes port 80 conflict hint', function (): void {
    $script = EnvoyAdminScript::liveStateProbeScript();

    expect($script)
        ->toContain('dply_envoy_admin_ready')
        ->toContain('port :80 is still occupied')
        ->toContain('journalctl -u envoy');
});

test('envoy admin wait script retries before failing', function (): void {
    $script = EnvoyAdminScript::waitUntilReady(attempts: 5, sleepSeconds: 2);

    expect($script)->toContain('while [ "$i" -lt 5 ]')
        ->toContain('sleep 2');
});

test('live state probe waits up to fifteen seconds', function (): void {
    $script = EnvoyAdminScript::liveStateProbeScript();

    expect($script)->toContain('while [ "$i" -lt 15 ]');
});

test('envoy admin failure script explains activating crash loop', function (): void {
    $script = EnvoyAdminScript::liveStateProbeScript();

    expect($script)->toContain('crash-looping')
        ->toContain('activating');
});

test('envoy start service script releases caddy from port 80', function (): void {
    $script = EnvoyAdminScript::startServiceScript();

    expect($script)->toContain('dply_release_caddy_port80')
        ->toContain('dply_install_edge_caddyfile')
        ->toContain('dply_envoy_port80_still_blocked_hint');
});

test('envoy cutover prep stops unit and checks port 80', function (): void {
    $script = EnvoyAdminScript::prepareForCutoverScript();

    expect($script)->toContain('systemctl stop envoy')
        ->toContain('systemctl reset-failed envoy')
        ->toContain('for u in haproxy traefik')
        ->toContain('dply_release_caddy_port80')
        ->toContain('Port :80 is still in use');
});

test('envoy start service script validates and waits for admin', function (): void {
    $script = EnvoyAdminScript::startServiceScript();

    expect($script)->toContain('--mode validate')
        ->toContain('systemctl start envoy')
        ->toContain('while [ "$i" -lt 25 ]');
});
