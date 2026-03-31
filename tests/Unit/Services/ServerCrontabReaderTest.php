<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\Servers\ServerCrontabReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServerCrontabReaderTest extends TestCase
{
    #[Test]
    public function it_rejects_invalid_linux_usernames(): void
    {
        $server = new Server([
            'status' => Server::STATUS_READY,
            'ssh_private_key' => 'not-empty',
        ]);

        $reader = new ServerCrontabReader;

        $this->expectException(\InvalidArgumentException::class);
        $reader->readForUser($server, 'not;valid');
    }
}
