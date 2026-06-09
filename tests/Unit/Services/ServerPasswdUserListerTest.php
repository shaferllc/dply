<?php

namespace Tests\Unit\Services\ServerPasswdUserListerTest;

use App\Models\Server;
use App\Services\Servers\ServerPasswdUserLister;

it('requires a ready server with ssh key', function () {
    $server = new Server([
        'status' => Server::STATUS_READY,
        'ssh_private_key' => '',
    ]);

    $lister = new ServerPasswdUserLister;

    $this->expectException(\RuntimeException::class);
    $lister->listUsernames($server);
});
