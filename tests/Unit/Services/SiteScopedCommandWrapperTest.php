<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SiteScopedCommandWrapper;
use PHPUnit\Framework\TestCase;

class SiteScopedCommandWrapperTest extends TestCase
{
    public function test_no_wrap_when_login_matches_effective_user(): void
    {
        $server = new Server(['ssh_user' => 'dply']);
        $site = new Site(['php_fpm_user' => null]);
        $site->setRelation('server', $server);

        $w = new SiteScopedCommandWrapper;
        $cmd = 'cd /srv && true';
        $this->assertSame($cmd, $w->wrapRemoteExec($site, $cmd));
    }

    public function test_wraps_with_sudo_when_effective_differs_from_deploy(): void
    {
        $server = new Server(['ssh_user' => 'dply']);
        $site = new Site(['php_fpm_user' => 'appuser']);
        $site->setRelation('server', $server);

        $w = new SiteScopedCommandWrapper;
        $out = $w->wrapRemoteExec($site, 'cd /srv && composer install');
        $this->assertStringStartsWith('sudo -n -u ', $out);
        $this->assertStringContainsString('appuser', $out);
    }
}
