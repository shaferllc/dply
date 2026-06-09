<?php

declare(strict_types=1);

use App\Support\Servers\CaddyEdgeBackendLayout;
use App\Support\Servers\EnvoyAdminScript;

test('edge caddyfile has no port 80 listener', function (): void {
    $contents = CaddyEdgeBackendLayout::canonicalCaddyfile();

    expect($contents)
        ->toContain('admin off')
        ->toContain('auto_https off')
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
        ->toContain('dply_strip_port80_caddy_site_fragments')
        ->toContain('dply_caddy_fragment_binds_port80')
        ->toContain('*-backend.caddy')
        ->toContain('dply-custom-*.caddy')
        ->toContain('systemctl restart caddy')
        ->toContain('exit 12')
        ->not->toContain('systemctl reload caddy');
});

test('strip port 80 listener fragments shell is valid bash', function (): void {
    $script = CaddyEdgeBackendLayout::stripPort80ListenerFragmentsShell();

    $path = sys_get_temp_dir().'/dply-caddy-p80-'.uniqid('', true).'.sh';
    file_put_contents($path, $script);

    try {
        exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exitCode);
        expect($exitCode)->toBe(0, implode("\n", $output));
    } finally {
        @unlink($path);
    }
});

test('port 80 listener strip removes fragments with explicit http listeners', function (): void {
    $dir = sys_get_temp_dir().'/dply-caddy-strip-'.uniqid('', true);
    mkdir($dir, 0755, true);

    file_put_contents($dir.'/catchall.caddy', ":80 {\n  respond ok\n}\n");
    file_put_contents($dir.'/app-backend.caddy', "example.test:20001 {\n  respond ok\n}\n");
    file_put_contents($dir.'/app-tls.caddy', "http://example.test {\n  respond ok\n}\n");

    $script = CaddyEdgeBackendLayout::stripPort80ListenerFragmentsShell()."\n"
        .'for f in '.escapeshellarg($dir).'/*.caddy; do'
        .' [ -e "$f" ] || continue;'
        .' if dply_caddy_fragment_binds_port80 "$f"; then rm -f "$f"; fi;'
        .' done';

    exec('bash -c '.escapeshellarg($script).' 2>&1', $output, $exitCode);
    expect($exitCode)->toBe(0, implode("\n", $output))
        ->and(file_exists($dir.'/catchall.caddy'))->toBeFalse()
        ->and(file_exists($dir.'/app-tls.caddy'))->toBeFalse()
        ->and(file_exists($dir.'/app-backend.caddy'))->toBeTrue();

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});

test('legacy strip removes primary site caddy files but keeps backend and tls', function (): void {
    $dir = sys_get_temp_dir().'/dply-caddy-legacy-'.uniqid('', true);
    mkdir($dir, 0755, true);

    file_put_contents($dir.'/app.caddy', "example.test {\n  respond ok\n}\n");
    file_put_contents($dir.'/app-backend.caddy', "example.test:20001 {\n  respond ok\n}\n");
    file_put_contents($dir.'/app-tls.caddy', "https://example.test {\n  respond ok\n}\n");

    $script = CaddyEdgeBackendLayout::stripLegacySiteFragmentsShell()."\n"
        .'SITES_DIR='.escapeshellarg($dir)."\n"
        .'dply_strip_legacy_caddy_site_fragments() {'."\n"
        .'  for f in "$SITES_DIR"/*.caddy; do'."\n"
        .'    [ -e "$f" ] || continue'."\n"
        .'    case "$f" in *-backend.caddy|*-tls.caddy) continue ;; esac'."\n"
        .'    rm -f "$f"'."\n"
        .'  done'."\n"
        .'}'."\n"
        .'dply_strip_legacy_caddy_site_fragments';

    exec('bash -c '.escapeshellarg($script).' 2>&1', $output, $exitCode);
    expect($exitCode)->toBe(0, implode("\n", $output))
        ->and(file_exists($dir.'/app.caddy'))->toBeFalse()
        ->and(file_exists($dir.'/app-backend.caddy'))->toBeTrue()
        ->and(file_exists($dir.'/app-tls.caddy'))->toBeTrue();

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
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
