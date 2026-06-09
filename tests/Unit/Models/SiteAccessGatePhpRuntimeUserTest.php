<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\Site;

test('access gate php runtime user prefers explicit php fpm user', function () {
    $server = new Server(['ssh_user' => 'deploy']);
    $site = new Site(['php_fpm_user' => 'appuser']);

    expect($site->accessGatePhpRuntimeUser($server))->toBe('appuser');
});

test('access gate php runtime user falls back to www-data for stock pools', function () {
    $server = new Server(['ssh_user' => 'deploy']);
    $site = new Site(['php_fpm_user' => null]);

    expect($site->accessGatePhpRuntimeUser($server))->toBe('www-data');
});
