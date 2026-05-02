<?php

namespace Tests\Unit\Models;

use App\Models\Server;
use App\Models\Site;
use PHPUnit\Framework\TestCase;

class SiteEffectiveSystemUserTest extends TestCase
{
    public function test_effective_system_user_prefers_explicit_php_fpm_user(): void
    {
        $server = new Server(['ssh_user' => 'deploy']);
        $site = new Site(['php_fpm_user' => 'custom']);

        $this->assertSame('custom', $site->effectiveSystemUser($server));
    }

    public function test_effective_system_user_falls_back_to_server_ssh_user(): void
    {
        $server = new Server(['ssh_user' => 'deploy']);
        $site = new Site(['php_fpm_user' => null]);

        $this->assertSame('deploy', $site->effectiveSystemUser($server));
    }
}
