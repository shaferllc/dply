<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\RemoteWebserverConfigServiceTest;
use App\Models\Server;
use App\Services\Servers\RemoteWebserverConfigService;
use App\Services\Servers\ServerManageSshExecutor;
function service(): RemoteWebserverConfigService
{
    // The executor would attempt real SSH if invoked; tests below all hit
    // paths that throw before reaching it.
    $executor = $this->createMock(ServerManageSshExecutor::class);

    return new RemoteWebserverConfigService($executor);
}
test('unsupported engine is rejected', function () {
    $server = new Server;

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/Unsupported webserver engine/');
    service()->read($server, 'lighttpd', '/etc/nginx/nginx.conf');
});
test('path outside allowlist is rejected for read', function () {
    $server = new Server;

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/Path not allowed/');
    service()->read($server, 'nginx', '/etc/passwd');
});
test('path outside allowlist is rejected for write', function () {
    $server = new Server;

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/Path not allowed/');
    service()->write($server, 'caddy', '/root/.bashrc', 'rm -rf /');
});
test('path traversal segment is rejected', function () {
    $server = new Server;

    $this->expectException(\InvalidArgumentException::class);

    // /etc/caddy/../etc/passwd starts with /etc/caddy/ but contains the
    // canonical-traversal segment, which the validator rejects.
    service()->read($server, 'caddy', '/etc/caddy/../etc/passwd');
});
test('relative path is rejected', function () {
    $server = new Server;

    $this->expectException(\InvalidArgumentException::class);
    service()->read($server, 'nginx', 'etc/nginx/nginx.conf');
});
test('write payload over max size is rejected', function () {
    $server = new Server;
    $max = (int) config('server_manage.config_edit_max_bytes', 256_000);
    $oversize = str_repeat('a', $max + 1);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/exceeds/');
    service()->write($server, 'nginx', '/etc/nginx/nginx.conf', $oversize);
});
test('restore rejects backup path outside engine backup dir', function () {
    $server = new Server;

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/outside the engine backup directory/');
    service()->restoreBackup(
        $server,
        'nginx',
        // Path is allowed (under /etc/nginx/) but NOT under _dply_backups —
        // a malicious caller can't bait the service into clobbering the live
        // file with itself, or with an arbitrary site fragment.
        '/etc/nginx/sites-available/default',
        '/etc/nginx/nginx.conf',
    );
});
test('supported engines match layout config', function () {
    expect(service()->supportedEngines())->toBe(array_keys((array) config('server_manage.webserver_config_layout', [])));
});
