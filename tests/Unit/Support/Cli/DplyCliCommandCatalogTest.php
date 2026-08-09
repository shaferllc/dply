<?php

declare(strict_types=1);

use App\Support\Cli\DplyCliCommandCatalog;

it('indexes organized cli command families', function () {
    $catalog = DplyCliCommandCatalog::forServer('01testserverid000000000000');

    expect($catalog['total'])->toBeGreaterThan(50)
        ->and($catalog['groups'])->not->toBeEmpty()
        ->and(collect($catalog['groups'])->pluck('key')->all())->toContain('server', 'site', 'edge', 'account');

    $serverShow = collect($catalog['entries'])->firstWhere('id', 'server-show');

    expect($serverShow)->not->toBeNull()
        ->and($serverShow['server_bound'])->toBeTrue()
        ->and($serverShow['command'])->toContain('--server 01testserverid000000000000')
        ->and($serverShow['scope'])->toBe('servers.read');

    $ids = collect($catalog['entries'])->pluck('id');
    expect($ids->unique()->count())->toBe($ids->count());
});
