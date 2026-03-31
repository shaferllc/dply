<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\Servers\ServerPasswdUserLister;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServerPasswdUserListerTest extends TestCase
{
    #[Test]
    public function it_requires_a_ready_server_with_ssh_key(): void
    {
        $server = new Server([
            'status' => Server::STATUS_READY,
            'ssh_private_key' => '',
        ]);

        $lister = new ServerPasswdUserLister;

        $this->expectException(\RuntimeException::class);
        $lister->listUsernames($server);
    }
}
