<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * Pure-config assertions on the new webserver service_actions allowlist:
 *
 * The blade template references these keys by hardcoded string (e.g.
 * `start_caddy`, `caddy_fmt_overwrite`). A typo or accidental removal would
 * make the corresponding button silently disappear from the UI rather than
 * fail loudly, so this test pins the contract.
 */
class WebserverServiceActionsConfigTest extends TestCase
{
    public function test_lifecycle_actions_are_registered_for_each_engine(): void
    {
        $actions = (array) config('server_manage.service_actions', []);
        foreach (['nginx', 'caddy', 'apache'] as $engine) {
            foreach (['start', 'stop', 'enable', 'disable'] as $verb) {
                $key = "{$verb}_{$engine}";
                $this->assertArrayHasKey($key, $actions, "Action {$key} missing from allowlist.");
                $this->assertNotEmpty($actions[$key]['script'] ?? null, "Action {$key} is missing its bash script.");
                $this->assertNotEmpty($actions[$key]['label'] ?? null, "Action {$key} is missing its label.");
                $this->assertNotEmpty($actions[$key]['confirm'] ?? null, "Action {$key} is missing its confirm copy.");
            }
        }
    }

    public function test_cli_tool_actions_are_registered(): void
    {
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
            $this->assertArrayHasKey($k, $actions, "Action {$k} missing from allowlist.");
            $this->assertNotEmpty($actions[$k]['script'] ?? null, "Action {$k} is missing its bash script.");
        }
    }

    public function test_caddy_and_apache_config_prefixes_are_allowlisted(): void
    {
        $prefixes = (array) config('server_manage.allowed_config_path_prefixes', []);
        $this->assertContains('/etc/caddy/', $prefixes);
        $this->assertContains('/etc/apache2/', $prefixes);
        $this->assertContains('/etc/nginx/', $prefixes); // still there
    }

    public function test_webserver_config_layout_has_required_keys_per_engine(): void
    {
        $layout = (array) config('server_manage.webserver_config_layout', []);
        foreach (['nginx', 'caddy', 'apache'] as $engine) {
            $this->assertArrayHasKey($engine, $layout, "Layout missing engine: {$engine}");
            $this->assertNotEmpty($layout[$engine]['main'] ?? null);
            $this->assertNotEmpty($layout[$engine]['validate'] ?? null);
        }
    }
}
