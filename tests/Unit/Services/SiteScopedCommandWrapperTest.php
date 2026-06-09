<?php

namespace Tests\Unit\Services\SiteScopedCommandWrapperTest;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SiteScopedCommandWrapper;

test('no wrap when login matches effective user', function () {
    $server = new Server(['ssh_user' => 'dply']);
    $site = new Site(['php_fpm_user' => null]);
    $site->setRelation('server', $server);

    $w = new SiteScopedCommandWrapper;
    $cmd = 'cd /srv && true';
    expect($w->wrapRemoteExec($site, $cmd))->toBe($cmd);
});

test('wraps with sudo when effective differs from deploy', function () {
    $server = new Server(['ssh_user' => 'dply']);
    $site = new Site(['php_fpm_user' => 'appuser']);
    $site->setRelation('server', $server);

    $w = new SiteScopedCommandWrapper;
    $out = $w->wrapRemoteExec($site, 'cd /srv && composer install');
    expect($out)->toStartWith('sudo -n -u ');
    $this->assertStringContainsString('appuser', $out);
});
