<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteNginxProvisionerFilesPresentTest;

use App\Models\Server;
use App\Services\Sites\NginxSiteConfigBuilder;
use App\Services\Sites\SiteNginxProvisioner;
use App\Services\SshConnection;
use Mockery;

/**
 * Faithfully simulate the box's shell: parse the generated
 *   test -f '<arg>' && echo "OK <marker>" || echo "NO <marker>"
 * lines and emit the literal marker the shell would print, evaluating
 * `test -f` against a known-present set. This reproduces the real round trip,
 * so a marker that wrongly carries escapeshellarg's single quotes (the
 * regression) flows through to filesPresentOnBox's own regex and is caught.
 *
 * @param  list<string>  $present
 */
function fakeShellSsh(array $present): SshConnection
{
    $ssh = Mockery::mock(SshConnection::class);
    $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) use ($present): string {
        $lines = [];
        foreach (preg_split('/\n/', $command) as $line) {
            if (! preg_match('/test -f \'([^\']*)\' && echo "(OK [^"]*)" \|\| echo "(NO [^"]*)"/', $line, $m)) {
                continue;
            }
            // $m[1] = the (single-quoted, now unquoted) path `test -f` checks.
            // $m[2]/$m[3] = the exact strings the shell would echo.
            $lines[] = in_array($m[1], $present, true) ? $m[2] : $m[3];
        }

        return implode("\n", $lines)."\n";
    });

    return $ssh;
}

function callFilesPresentOnBox(Server $server, SshConnection $ssh, array $paths): array
{
    $provisioner = new SiteNginxProvisioner(new NginxSiteConfigBuilder);
    $ref = new \ReflectionMethod($provisioner, 'filesPresentOnBox');
    $ref->setAccessible(true);

    return $ref->invoke($provisioner, $server, $ssh, $paths);
}

// ssh_user 'root' keeps privilegedCommand from wrapping the checks in
// `sudo -n bash -lc '...'`, so the fake shell sees the raw marker commands.
function rootServer(): Server
{
    return new Server(['name' => 'box', 'ip_address' => '203.0.113.10', 'ssh_user' => 'root']);
}

test('reports a missing cert as absent so the TLS salvage can fire', function () {
    $missing = '/etc/letsencrypt/live/tracely-47cafd36.on-dply.cc/fullchain.pem';
    $wildcard = '/etc/letsencrypt/live/on-dply.cc/fullchain.pem';

    // The box has only the covering wildcard, not the per-host testing cert.
    $ssh = fakeShellSsh([$wildcard]);

    $present = callFilesPresentOnBox(rootServer(), $ssh, [$missing, $wildcard]);

    // Regression: the OK/NO markers used escapeshellarg's single-quoted value,
    // so the box emitted `NO '<path>'` which never matched the `^NO <path>$`
    // regex — every path failed open to "present" and the salvage was silently
    // skipped, letting the missing cert reach (and fail) nginx -t.
    expect($present[$missing])->toBeFalse()
        ->and($present[$wildcard])->toBeTrue();
});

test('reports present certs as present', function () {
    $cert = '/etc/letsencrypt/live/app.example.com/fullchain.pem';
    $key = '/etc/letsencrypt/live/app.example.com/privkey.pem';

    $present = callFilesPresentOnBox(rootServer(), fakeShellSsh([$cert, $key]), [$cert, $key]);

    expect($present[$cert])->toBeTrue()
        ->and($present[$key])->toBeTrue();
});
