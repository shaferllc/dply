<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteRuntimeReconcilerTest;

use App\Actions\Sites\SetSiteRuntime;
use App\Models\Site;
use App\Services\Sites\InternalPortAllocator;
use App\Services\Sites\SiteRuntimeReconciler;
use InvalidArgumentException;
use Mockery;

/**
 * @param  array<string, mixed>  $detected
 */
function siteWith(array $detected, string $runtime = 'php', ?int $internalPort = null): Site
{
    $site = new Site;
    $site->forceFill([
        'id' => '01hzzzzzzzzzzzzzzzzzzzzzzz',
        'server_id' => '01hyyyyyyyyyyyyyyyyyyyyyyy',
        'runtime' => $runtime,
        'internal_port' => $internalPort,
        'meta' => ['vm_runtime' => ['detected' => $detected]],
    ]);

    return $site;
}

/** @return array<string, mixed> */
function nodeDetection(?string $startCommand): array
{
    return array_filter([
        'language' => 'node',
        'framework' => 'node_generic',
        'confidence' => 'medium',
        'package_manager' => 'npm',
        'start_command' => $startCommand,
    ], static fn ($v) => $v !== null);
}

/** A reconciler whose port allocation is scripted; the real allocator is final. */
function reconciler(SetSiteRuntime $setRuntime, ?int $port = null, bool $expectAllocate = true): SiteRuntimeReconciler
{
    return new class($setRuntime, $port, $expectAllocate) extends SiteRuntimeReconciler
    {
        public bool $allocated = false;

        public function __construct(
            SetSiteRuntime $setRuntime,
            private readonly ?int $port,
            public readonly bool $expectAllocate,
        ) {
            parent::__construct($setRuntime, app(InternalPortAllocator::class));
        }

        protected function allocatePort(string $serverId): ?int
        {
            $this->allocated = true;

            return $this->port;
        }
    };
}

afterEach(fn () => Mockery::close());

test('a detected node repo with a start command moves the site onto node', function () {
    $setRuntime = Mockery::mock(SetSiteRuntime::class);

    $captured = null;
    $setRuntime->shouldReceive('handle')->once()
        ->withArgs(function (Site $site, array $changes) use (&$captured) {
            $captured = $changes;

            return true;
        });

    $note = reconciler($setRuntime, 9101)
        ->reconcile(siteWith(nodeDetection('npm start')));

    expect($captured)->toBe([
        'runtime' => 'node',
        'start_command' => 'npm start',
        'internal_port' => 9101,
    ])->and($note)->toContain('switched this site from php to node');
});

test('a node repo that declares no start command is left on php', function () {
    // wisp: a Cloudflare Workers package. Nothing listens on a port, so
    // proxying to one would turn a rendering page into a permanent 502.
    $setRuntime = Mockery::mock(SetSiteRuntime::class);
    $setRuntime->shouldNotReceive('handle');

    $note = reconciler($setRuntime, 9101)
        ->reconcile(siteWith(nodeDetection(null)));

    expect($note)->toContain('declares no start command')
        ->and($note)->toContain('stays on php');
});

test('an existing internal port is reused rather than reallocated', function () {
    $setRuntime = Mockery::mock(SetSiteRuntime::class);

    $captured = null;
    $setRuntime->shouldReceive('handle')->once()
        ->withArgs(function (Site $site, array $changes) use (&$captured) {
            $captured = $changes;

            return true;
        });

    reconciler($setRuntime, 9101)
        ->reconcile(siteWith(nodeDetection('npm start'), internalPort: 9200));

    expect($captured)->not->toHaveKey('internal_port');
});

test('a site already on the detected runtime is left alone', function () {
    $setRuntime = Mockery::mock(SetSiteRuntime::class);
    $setRuntime->shouldNotReceive('handle');

    $note = reconciler($setRuntime)
        ->reconcile(siteWith(nodeDetection('npm start'), runtime: 'node'));

    expect($note)->toBeNull();
});

test('an unrecognised language never moves the site', function () {
    $setRuntime = Mockery::mock(SetSiteRuntime::class);
    $setRuntime->shouldNotReceive('handle');

    $note = reconciler($setRuntime)
        ->reconcile(siteWith(['language' => 'unknown', 'framework' => 'unknown']));

    expect($note)->toBeNull();
});

test('a refused switch reports the reason instead of failing the deploy', function () {
    $setRuntime = Mockery::mock(SetSiteRuntime::class);
    $setRuntime->shouldReceive('handle')->once()
        ->andThrow(new InvalidArgumentException('Node is not installed on playground.'));

    $note = reconciler($setRuntime, 9101)
        ->reconcile(siteWith(nodeDetection('npm start')));

    expect($note)->toContain('could not switch')
        ->and($note)->toContain('Node is not installed on playground.');
});
