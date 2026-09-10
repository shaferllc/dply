<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SystemUserRowsFlagFtpAccountsTest;

use App\Models\Server;
use App\Models\ServerSystemUser;
use App\Models\SftpAccount;
use App\Services\Servers\ServerSshConnectionRunner;
use App\Services\Servers\ServerSystemUserDeletionPolicy;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

/**
 * Both features useradd into one /etc/passwd, so the System users page is the
 * single list over that namespace. FTP accounts appear there flagged, rather
 * than in a second table that could drift out of sync with this one.
 */
test('system user rows flag FTP accounts and never call them orphans', function () {
    $server = Server::factory()->create(['ssh_user' => 'dply']);

    foreach (['designer', 'alice'] as $username) {
        ServerSystemUser::query()->create([
            'server_id' => $server->id,
            'username' => $username,
            'uid' => 1001,
            'home' => '/home/'.$username,
            'shell' => '/bin/bash',
            'groups' => [],
        ]);
    }

    SftpAccount::factory()->create([
        'server_id' => $server->id,
        'username' => 'designer',
        'home_path' => '/home/designer',
    ]);

    $service = new ServerSystemUserService(
        Mockery::mock(ServerSshConnectionRunner::class),
        new ServerSystemUserDeletionPolicy,
    );

    $rows = collect($service->storedSystemUsersWithMetadata($server))->keyBy('username');

    // An FTP account owns no sites, workers or crons by design. Without the
    // flag it would be labelled an orphan and the page would invite the
    // operator to delete a working account.
    expect($rows['designer']['is_sftp'])->toBeTrue()
        ->and($rows['designer']['is_orphan'])->toBeFalse();

    // A genuinely unused shell account is still an orphan.
    expect($rows['alice']['is_sftp'])->toBeFalse()
        ->and($rows['alice']['is_orphan'])->toBeTrue();
});
