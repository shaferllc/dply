<?php

declare(strict_types=1);

namespace Tests\Unit\Config\WebserverServiceActionsConfigTest;
test('lifecycle actions are registered for each engine', function () {
    $actions = (array) config('server_manage.service_actions', []);
    foreach (['nginx', 'caddy', 'apache'] as $engine) {
        foreach (['start', 'stop', 'enable', 'disable'] as $verb) {
            $key = "{$verb}_{$engine}";
            expect($actions)->toHaveKey($key, "Action {$key} missing from allowlist.");
            expect($actions[$key]['script'] ?? null)->not->toBeEmpty("Action {$key} is missing its bash script.");
            expect($actions[$key]['label'] ?? null)->not->toBeEmpty("Action {$key} is missing its label.");
            expect($actions[$key]['confirm'] ?? null)->not->toBeEmpty("Action {$key} is missing its confirm copy.");
        }
    }
});
test('cli tool actions are registered', function () {
    $keys = [
        'caddy_fmt_preview',
        'caddy_fmt_overwrite',
        'caddy_adapt',
        'caddy_environ',
        'caddy_version',
        'caddy_list_modules',
        'nginx_build_info',
        'nginx_effective_config',
        'nginx_reopen_logs',
        'apache_modules',
        'apache_vhosts',
        'apache_build_info',
    ];
    $actions = (array) config('server_manage.service_actions', []);
    foreach ($keys as $k) {
        expect($actions)->toHaveKey($k, "Action {$k} missing from allowlist.");
        expect($actions[$k]['script'] ?? null)->not->toBeEmpty("Action {$k} is missing its bash script.");
    }
});
test('caddy and apache config prefixes are allowlisted', function () {
    $prefixes = (array) config('server_manage.allowed_config_path_prefixes', []);
    expect($prefixes)->toContain('/etc/caddy/');
    expect($prefixes)->toContain('/etc/apache2/');
    expect($prefixes)->toContain('/etc/nginx/');
    // still there
});
test('webserver config layout has required keys per engine', function () {
    $layout = (array) config('server_manage.webserver_config_layout', []);
    foreach (['nginx', 'caddy', 'apache'] as $engine) {
        expect($layout)->toHaveKey($engine, "Layout missing engine: {$engine}");
        expect($layout[$engine]['main'] ?? null)->not->toBeEmpty();
        expect($layout[$engine]['validate'] ?? null)->not->toBeEmpty();
    }
});
