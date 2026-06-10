<?php

declare(strict_types=1);

namespace Tests\Unit\SitePhpFpmPoolConfigBuilderTest;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SitePhpFpmPoolConfigBuilder;

function poolSite(array $poolMeta = [], string $sshUser = 'dply'): array
{
    $server = new Server();
    $server->ssh_user = $sshUser;
    $server->meta = ['php_inventory' => ['installed_versions' => ['8.4'], 'detected_default_version' => '8.4']];

    $site = new Site();
    $site->type = SiteType::Php;
    $site->runtime = 'php';
    $site->runtime_version = '8.4';
    $site->meta = array_merge(['webserver_config_basename' => 'dply-example.com-01ktabc'], $poolMeta === [] ? [] : ['php_fpm_pool' => $poolMeta]);
    $site->setRelation('server', $server);

    return [$site, $server];
}

test('builds a dynamic pool with derived spare servers', function () {
    [$site, $server] = poolSite(['pm' => 'dynamic', 'max_children' => 20, 'max_requests' => 1000, 'request_terminate_timeout' => 90]);

    $conf = (new SitePhpFpmPoolConfigBuilder())->build($site, $server);

    expect($conf)->toContain('[dply-example.com-01ktabc]');
    expect($conf)->toContain('user = dply');
    expect($conf)->toContain('group = www-data');
    expect($conf)->toContain('listen = /run/php/dply-example.com-01ktabc.sock');
    expect($conf)->toContain('listen.owner = www-data');
    expect($conf)->toContain('listen.mode = 0660');
    expect($conf)->toContain('pm = dynamic');
    expect($conf)->toContain('pm.max_children = 20');
    expect($conf)->toContain('pm.start_servers = 5');
    expect($conf)->toContain('pm.min_spare_servers = 5');
    expect($conf)->toContain('pm.max_spare_servers = 10');
    expect($conf)->toContain('pm.max_requests = 1000');
    expect($conf)->toContain('request_terminate_timeout = 90s');
});

test('pool logs the per-request reference for error correlation', function () {
    [$site, $server] = poolSite(['pm' => 'dynamic', 'max_children' => 20, 'max_requests' => 1000, 'request_terminate_timeout' => 90]);

    $conf = (new SitePhpFpmPoolConfigBuilder())->build($site, $server);

    // The access log carries the REQUEST_ID (set by nginx fastcgi_param) plus an
    // epoch, so a 5xx reference code resolves to the exact request.
    expect($conf)->toContain('access.log = '.$site->phpFpmAccessLogPath());
    expect($conf)->toContain('%{REQUEST_ID}e');
    expect($conf)->toContain('php_admin_value[error_log] = '.$site->phpFpmPoolErrorLogPath());
});

test('static pool omits spare-server tuning', function () {
    [$site, $server] = poolSite(['pm' => 'static', 'max_children' => 8]);

    $conf = (new SitePhpFpmPoolConfigBuilder())->build($site, $server);

    expect($conf)->toContain('pm = static');
    expect($conf)->toContain('pm.max_children = 8');
    expect($conf)->not->toContain('pm.start_servers');
    expect($conf)->not->toContain('pm.min_spare_servers');
});

test('ondemand pool sets a process idle timeout', function () {
    [$site, $server] = poolSite(['pm' => 'ondemand', 'max_children' => 5]);

    $conf = (new SitePhpFpmPoolConfigBuilder())->build($site, $server);

    expect($conf)->toContain('pm = ondemand');
    expect($conf)->toContain('pm.process_idle_timeout = 10s');
    expect($conf)->not->toContain('pm.start_servers');
});

test('pool settings fall back to defaults and clamp junk values', function () {
    [$site] = poolSite(['pm' => 'bogus', 'max_children' => 0, 'max_requests' => 'x', 'request_terminate_timeout' => -5]);

    expect($site->phpFpmPoolSettings())->toBe([
        'pm' => 'dynamic',
        'max_children' => 10,
        'max_requests' => 500,
        'request_terminate_timeout' => 120,
    ]);
});

test('listen socket is version-free and matches the pool name', function () {
    [$site] = poolSite();

    expect($site->phpFpmPoolName())->toBe('dply-example.com-01ktabc');
    expect($site->phpFpmListenSocketPath())->toBe('/run/php/dply-example.com-01ktabc.sock');
    // No PHP version anywhere in the socket path — a version switch never
    // rewrites the vhost.
    expect($site->phpFpmListenSocketPath())->not->toContain('8.4');
});

test('nginx and caddy php sites use the dedicated pool; octane opts out', function () {
    [$site] = poolSite();
    expect($site->usesDedicatedPhpFpmPool())->toBeTrue();

    $site->octane_port = 8000;
    expect($site->usesDedicatedPhpFpmPool())->toBeFalse();
});
