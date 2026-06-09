<?php

namespace Tests\Unit\Models\SiteEffectiveSystemUserTest;

use App\Models\Server;
use App\Models\Site;

test('effective system user prefers explicit php fpm user', function () {
    $server = new Server(['ssh_user' => 'deploy']);
    $site = new Site(['php_fpm_user' => 'custom']);

    expect($site->effectiveSystemUser($server))->toBe('custom');
});

test('effective system user falls back to server ssh user', function () {
    $server = new Server(['ssh_user' => 'deploy']);
    $site = new Site(['php_fpm_user' => null]);

    expect($site->effectiveSystemUser($server))->toBe('deploy');
});
