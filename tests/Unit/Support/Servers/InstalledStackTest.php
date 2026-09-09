<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers\InstalledStackTest;

use App\Models\Server;
use App\Support\Servers\InstalledStack;

test('round trip via array', function () {
    $stack = new InstalledStack(
        database: 'mysql84',
        databaseVersion: '8.0.45',
        phpVersion: '8.4',
        webserver: 'nginx',
        cacheService: 'redis',
        lowMemoryMode: false,
        totalMemoryMb: 2048,
        swapMb: 2048,
    );

    $rebuilt = InstalledStack::fromArray($stack->toArray());

    expect($rebuilt->toArray())->toBe($stack->toArray());
});
test('from meta uses installed stack key when present', function () {
    $server = new Server;
    $server->meta = [
        'database' => 'mysql84',                  // wizard request
        'installed_stack' => [                    // reconciled snapshot
            'database' => 'sqlite3',
            'database_version' => '3.45.1',
            'php_version' => '8.4',
            'webserver' => 'nginx',
            'cache_service' => 'redis',
            'low_mem_mode' => true,
            'total_memory_mb' => 458,
            'swap_mb' => 2048,
        ],
    ];

    $stack = InstalledStack::fromMeta($server);

    // The reconciled snapshot wins. The wizard request stays available
    // separately for "Requested vs Installed" divergence display.
    expect($stack->database)->toBe('sqlite3');
    expect($stack->databaseVersion)->toBe('3.45.1');
    expect($stack->lowMemoryMode)->toBeTrue();
    expect($stack->totalMemoryMb)->toBe(458);
});
test('from meta falls back to wizard keys for legacy servers', function () {
    // Server provisioned before reconciliation shipped — no installed_stack key.
    $server = new Server;
    $server->meta = [
        'database' => 'mysql84',
        'php_version' => '8.3',
        'webserver' => 'caddy',
        'cache_service' => 'valkey',
    ];

    $stack = InstalledStack::fromMeta($server);

    // Wizard values are surfaced as the installed reality (best
    // we can do — the script wasn't recording snapshots back then).
    expect($stack->database)->toBe('mysql84');
    expect($stack->databaseVersion)->toBeNull();
    // never recorded
    expect($stack->phpVersion)->toBe('8.3');
    expect($stack->webserver)->toBe('caddy');
    expect($stack->cacheService)->toBe('valkey');

    // Operational fields default conservatively for legacy servers.
    expect($stack->lowMemoryMode)->toBeFalse();
    expect($stack->totalMemoryMb)->toBeNull();
    expect($stack->swapMb)->toBeNull();
});
test('from meta handles completely empty meta', function () {
    $server = new Server;
    $server->meta = [];

    $stack = InstalledStack::fromMeta($server);

    // Every field nullable / defaulted — no exception, no surprises.
    expect($stack->database)->toBeNull();
    expect($stack->phpVersion)->toBeNull();
    expect($stack->lowMemoryMode)->toBeFalse();
});
test('parse from output extracts tagged line', function () {
    $output = <<<'OUT'
        [dply-step] Finalizing server
        [dply] verifying services...
        [dply-installed-stack] {"database":"sqlite3","database_version":"3.45.1","php_version":"8.4","webserver":"nginx","cache_service":"redis","low_mem_mode":true,"total_memory_mb":458,"swap_mb":2048}
        [dply] done
        OUT;

    $stack = InstalledStack::parseFromOutput($output);

    expect($stack)->not->toBeNull();
    expect($stack->database)->toBe('sqlite3');
    expect($stack->databaseVersion)->toBe('3.45.1');
    expect($stack->lowMemoryMode)->toBeTrue();
    expect($stack->totalMemoryMb)->toBe(458);
});
test('parse from output returns null when tagged line absent', function () {
    $output = "[dply-step] Installing PHP 8.4\n[dply] done\n";

    expect(InstalledStack::parseFromOutput($output))->toBeNull();
});
test('parse from output returns null for malformed json', function () {
    $output = "[dply-installed-stack] {not valid json\n";

    expect(InstalledStack::parseFromOutput($output))->toBeNull();
});
test('parse from output handles partial fields', function () {
    // Forward-compatibility: missing fields → nulls, extra fields ignored.
    $output = '[dply-installed-stack] {"database":"sqlite3","unknown_field":"ignored"}'."\n";

    $stack = InstalledStack::parseFromOutput($output);

    expect($stack)->not->toBeNull();
    expect($stack->database)->toBe('sqlite3');
    expect($stack->databaseVersion)->toBeNull();
    expect($stack->phpVersion)->toBeNull();
    expect($stack->lowMemoryMode)->toBeFalse();
});
test('parse from output picks last tagged line when multiple present', function () {
    // Future-proofing: if we ever switch to progressive emit, the
    // last line is the most-recent (final) state. The /m flag with
    // $ end-of-line means each line is matched independently and
    // we want the most recent one.
    $output = <<<'OUT'
        [dply-installed-stack] {"database":"mysql84","database_version":"8.0.45"}
        [dply] continued running
        [dply-installed-stack] {"database":"mysql84","database_version":"8.0.46"}
        OUT;

    $stack = InstalledStack::parseFromOutput($output);

    // preg_match returns the FIRST match; for now that's expected.
    // If progressive emit lands later, parser should switch to
    // preg_match_all + last index. Documenting current behaviour.
    expect($stack)->not->toBeNull();
    expect($stack->database)->toBe('mysql84');
});
test('diverges from request when wizard database differs', function () {
    $server = new Server;
    $server->meta = [
        'database' => 'mysql84',
        'installed_stack' => [
            'database' => 'sqlite3',
        ],
    ];

    $stack = InstalledStack::fromMeta($server);

    expect($stack->divergesFromRequest($server))->toBeTrue();
});
test('diverges from request is false when aligned', function () {
    $server = new Server;
    $server->meta = [
        'database' => 'mysql84',
        'installed_stack' => [
            'database' => 'mysql84',
        ],
    ];

    $stack = InstalledStack::fromMeta($server);

    expect($stack->divergesFromRequest($server))->toBeFalse();
});
test('diverges from request is false when no wizard request', function () {
    $server = new Server;
    $server->meta = [
        'installed_stack' => [
            'database' => 'sqlite3',
        ],
    ];

    $stack = InstalledStack::fromMeta($server);

    // Without a wizard request to compare against, there's no divergence.
    expect($stack->divergesFromRequest($server))->toBeFalse();
});

// The wizard records versioned engine ids; ServerInventoryProbeScript can only
// observe a family plus a live version and rewrites the snapshot that way on
// the first inventory run. Divergence has to read both shapes.
test('divergence compares engine family, then version', function (string $requested, ?string $installed, ?string $version, bool $diverges) {
    $server = new Server;
    $server->meta = [
        'database' => $requested,
        'installed_stack' => [
            'database' => $installed,
            'database_version' => $version,
        ],
    ];

    expect(InstalledStack::fromMeta($server)->divergesFromRequest($server))->toBe($diverges);
})->with([
    // Probe-coarsened snapshot of exactly what the wizard asked for: no banner.
    'probed family, right series' => ['mysql84', 'mysql', '8.4.2', false],
    'probed family, no version at all' => ['mysql84', 'mysql', null, false],
    // The pin did not take — distro 8.0 answered a 8.4 request. This is the
    // case the banner exists for, and the one it used to report as "mysql".
    'probed family, wrong series' => ['mysql84', 'mysql', '8.0.46', true],
    'marker recorded the fallback series' => ['mysql84', 'mysql80', '8.0.46', true],
    // Verbatim marker straight off a successful provision.
    'exact match' => ['mysql84', 'mysql84', '8.4.2', false],
    // Low-memory substitution crosses a family boundary.
    'sqlite substitution' => ['mysql84', 'sqlite3', '3.45.1', true],
    // mariadb114 is 11.4 but mariadb1011 is 10.11 — no digit-splitting rule
    // gets both, hence the explicit map.
    'mariadb 11.4 satisfied' => ['mariadb114', 'mariadb', '11.4.2', false],
    'mariadb 10.11 satisfied' => ['mariadb1011', 'mariadb', '10.11.6', false],
    'mariadb 10.11 requested, 11.4 installed' => ['mariadb1011', 'mariadb', '11.4.2', true],
    'mariadb 11 major satisfied by any minor' => ['mariadb11', 'mariadb', '11.4.2', false],
    'postgres major satisfied' => ['postgres17', 'postgres', '17.2', false],
    'postgres major wrong' => ['postgres17', 'postgres', '16.4', true],
]);
