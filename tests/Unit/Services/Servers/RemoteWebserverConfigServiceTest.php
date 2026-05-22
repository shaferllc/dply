<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Models\Server;
use App\Services\Servers\RemoteWebserverConfigService;
use App\Services\Servers\ServerManageSshExecutor;
use Tests\TestCase;

/**
 * Path safety and engine guards on the webserver config service.
 *
 * These tests only exercise the validation surface; the actual SSH calls
 * never happen because every assertion-throwing path short-circuits before
 * touching the executor. That's why a dummy unconfigured executor instance
 * is enough — if it ever did get called the test would fail with a different
 * error (likely a connect timeout) and we'd notice.
 */
class RemoteWebserverConfigServiceTest extends TestCase
{
    private function service(): RemoteWebserverConfigService
    {
        // The executor would attempt real SSH if invoked; tests below all hit
        // paths that throw before reaching it.
        $executor = $this->createMock(ServerManageSshExecutor::class);

        return new RemoteWebserverConfigService($executor);
    }

    public function test_unsupported_engine_is_rejected(): void
    {
        $server = new Server;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported webserver engine/');
        $this->service()->read($server, 'lighttpd', '/etc/nginx/nginx.conf');
    }

    public function test_path_outside_allowlist_is_rejected_for_read(): void
    {
        $server = new Server;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Path not allowed/');
        $this->service()->read($server, 'nginx', '/etc/passwd');
    }

    public function test_path_outside_allowlist_is_rejected_for_write(): void
    {
        $server = new Server;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Path not allowed/');
        $this->service()->write($server, 'caddy', '/root/.bashrc', 'rm -rf /');
    }

    public function test_path_traversal_segment_is_rejected(): void
    {
        $server = new Server;

        $this->expectException(\InvalidArgumentException::class);
        // /etc/caddy/../etc/passwd starts with /etc/caddy/ but contains the
        // canonical-traversal segment, which the validator rejects.
        $this->service()->read($server, 'caddy', '/etc/caddy/../etc/passwd');
    }

    public function test_relative_path_is_rejected(): void
    {
        $server = new Server;

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->read($server, 'nginx', 'etc/nginx/nginx.conf');
    }

    public function test_write_payload_over_max_size_is_rejected(): void
    {
        $server = new Server;
        $max = (int) config('server_manage.config_edit_max_bytes', 256_000);
        $oversize = str_repeat('a', $max + 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceeds/');
        $this->service()->write($server, 'nginx', '/etc/nginx/nginx.conf', $oversize);
    }

    public function test_restore_rejects_backup_path_outside_engine_backup_dir(): void
    {
        $server = new Server;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/outside the engine backup directory/');
        $this->service()->restoreBackup(
            $server,
            'nginx',
            // Path is allowed (under /etc/nginx/) but NOT under _dply_backups —
            // a malicious caller can't bait the service into clobbering the live
            // file with itself, or with an arbitrary site fragment.
            '/etc/nginx/sites-available/default',
            '/etc/nginx/nginx.conf',
        );
    }

    public function test_supported_engines_match_layout_config(): void
    {
        $this->assertSame(
            array_keys((array) config('server_manage.webserver_config_layout', [])),
            $this->service()->supportedEngines(),
        );
    }
}
