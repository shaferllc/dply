<?php

declare(strict_types=1);

use App\Support\Servers\CaddyEdgeBackendLayout;
use App\Support\Servers\EnvoyAdminScript;

test('edge caddyfile has no port 80 listener', function (): void {
    $contents = CaddyEdgeBackendLayout::canonicalCaddyfile();

    expect($contents)
        ->toContain('admin off')
        ->toContain('import /etc/caddy/sites-enabled/*.caddy')
        ->not->toContain(':80 {');
});

test('edge caddy release script is valid bash', function (): void {
    $script = CaddyEdgeBackendLayout::releasePort80Shell();

    $path = sys_get_temp_dir().'/dply-caddy-release-'.uniqid('', true).'.sh';
    file_put_contents($path, $script);

    try {
        exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exitCode);
        expect($exitCode)->toBe(0, implode("\n", $output));
    } finally {
        @unlink($path);
    }
});

test('edge caddy release script replaces default caddyfile and strips legacy sites', function (): void {
    $script = CaddyEdgeBackendLayout::releasePort80Shell();

    expect($script)
        ->toContain('dply_install_edge_caddyfile')
        ->toContain('DPLY_EDGE_CADDYFILE')
        ->toContain('dply_strip_legacy_caddy_site_fragments')
        ->toContain('*-backend.caddy')
        ->toContain('dply-custom-*.caddy')
        ->toContain('systemctl reload caddy');
});

test('strip legacy site fragments shell is valid bash', function (): void {
    $script = CaddyEdgeBackendLayout::stripLegacySiteFragmentsShell();

    $path = sys_get_temp_dir().'/dply-caddy-strip-'.uniqid('', true).'.sh';
    file_put_contents($path, $script);

    try {
        exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exitCode);
        expect($exitCode)->toBe(0, implode("\n", $output));
    } finally {
        @unlink($path);
    }
});

test('envoy start service script releases caddy from port 80', function (): void {
    $script = EnvoyAdminScript::startServiceScript();

    expect($script)->toContain('dply_release_caddy_port80')
        ->toContain('dply_install_edge_caddyfile');
});
