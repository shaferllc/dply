<?php

declare(strict_types=1);

use App\Support\Servers\NginxServiceScript;

test('nginx test and reload script is valid bash and starts when inactive', function () {
    $script = NginxServiceScript::testAndReloadOrStartScript();

    expect($script)->toContain('nginx -t')
        ->toContain('systemctl is-active --quiet nginx')
        ->toContain('systemctl reload nginx')
        ->toContain('systemctl enable --now nginx')
        ->toContain('rm -f /run/nginx.pid');

    $path = tempnam(sys_get_temp_dir(), 'dply-nginx-script-');
    file_put_contents($path, $script);
    exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exitCode);
    @unlink($path);

    expect($exitCode)->toBe(0, implode("\n", $output));
});
