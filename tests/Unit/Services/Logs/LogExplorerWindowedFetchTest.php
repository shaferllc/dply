<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Logs\LogExplorerWindowedFetchTest;

use App\Livewire\Servers\Concerns\ManagesServerLogExplorer;
use App\Models\Server;
use App\Modules\Logs\Services\LogExplorerQuery;
use Mockery;

/**
 * The explorer trait branches between the rolling recent() window and a pinned
 * window() slice (a correlation deep-link, e.g. error → logs). These exercise the
 * branch + the graceful-degrade contract without booting a Livewire component or
 * touching ClickHouse — a plain host exposing the protected fetch is enough.
 */
class ExplorerHarness
{
    use ManagesServerLogExplorer;

    public Server $server;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    /** @return array<string,mixed> */
    public function fetch(): array
    {
        return $this->loadLogExplorer();
    }
}

function harness(): ExplorerHarness
{
    return new ExplorerHarness(Server::factory()->make(['id' => 'srv_x', 'organization_id' => 'org_x']));
}

test('a valid from/to pins the explorer to window() and reports the window', function () {
    $query = Mockery::mock(LogExplorerQuery::class);
    $query->shouldReceive('window')->once()->andReturn([['message' => 'pinned line']]);
    $query->shouldReceive('recent')->never();
    app()->instance(LogExplorerQuery::class, $query);

    $h = harness();
    $h->logExplorerFrom = '2026-06-17T12:00:00+00:00';
    $h->logExplorerTo = '2026-06-17T12:05:00+00:00';

    $result = $h->fetch();

    expect($result['available'])->toBeTrue();
    expect($result['windowed'])->toBeTrue();
    expect($result['from'])->toContain('2026-06-17T12:00:00');
    expect($result['to'])->toContain('2026-06-17T12:05:00');
    expect($result['rows'])->toHaveCount(1);
    expect($h->isLogExplorerWindowed())->toBeTrue();
});

test('no from/to uses the rolling recent() window', function () {
    $query = Mockery::mock(LogExplorerQuery::class);
    $query->shouldReceive('recent')->once()->andReturn([['message' => 'recent line']]);
    $query->shouldReceive('window')->never();
    app()->instance(LogExplorerQuery::class, $query);

    $result = harness()->fetch();

    expect($result['windowed'])->toBeFalse();
    expect($result['from'])->toBeNull();
    expect($result['rows'])->toHaveCount(1);
});

test('a half-set window (from only) is NOT treated as pinned', function () {
    $query = Mockery::mock(LogExplorerQuery::class);
    $query->shouldReceive('recent')->once()->andReturn([]);
    $query->shouldReceive('window')->never();
    app()->instance(LogExplorerQuery::class, $query);

    $h = harness();
    $h->logExplorerFrom = '2026-06-17T12:00:00+00:00';

    expect($h->fetch()['windowed'])->toBeFalse();
    expect($h->isLogExplorerWindowed())->toBeFalse();
});

test('backToLiveLogs clears the pinned window', function () {
    $h = harness();
    $h->logExplorerFrom = '2026-06-17T12:00:00+00:00';
    $h->logExplorerTo = '2026-06-17T12:05:00+00:00';

    $h->backToLiveLogs();

    expect($h->logExplorerFrom)->toBe('');
    expect($h->logExplorerTo)->toBe('');
    expect($h->isLogExplorerWindowed())->toBeFalse();
});

test('a ClickHouse outage in windowed mode degrades gracefully and keeps the flag', function () {
    $query = Mockery::mock(LogExplorerQuery::class);
    $query->shouldReceive('window')->once()->andThrow(new \RuntimeException('clickhouse down'));
    app()->instance(LogExplorerQuery::class, $query);

    $h = harness();
    $h->logExplorerFrom = '2026-06-17T12:00:00+00:00';
    $h->logExplorerTo = '2026-06-17T12:05:00+00:00';

    $result = $h->fetch();

    expect($result['available'])->toBeFalse();
    expect($result['windowed'])->toBeTrue();
    expect($result['rows'])->toBe([]);
});
