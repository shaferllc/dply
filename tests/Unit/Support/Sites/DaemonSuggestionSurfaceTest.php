<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sites\DaemonSuggestionSurfaceTest;

use App\Support\Sites\SiteDaemonAdvisor;

/** @return list<array<string, mixed>> */
function suggestions(): array
{
    return [
        ['key' => 'horizon', 'surface' => SiteDaemonAdvisor::SURFACE_QUEUE],
        ['key' => 'queue', 'surface' => SiteDaemonAdvisor::SURFACE_QUEUE],
        ['key' => 'reverb', 'surface' => SiteDaemonAdvisor::SURFACE_WORKERS],
        ['key' => 'scheduler', 'surface' => SiteDaemonAdvisor::SURFACE_SCHEDULE],
    ];
}

test('horizon and queue:work belong to Queue, the scheduler to Schedule, reverb to Workers', function () {
    // A Set-up button has to create the thing on the page you will then look
    // for it on — Horizon appearing under Workers is what prompted this.
    $of = fn (string $surface): array => array_column(
        SiteDaemonAdvisor::onlyForSurface(suggestions(), $surface),
        'key',
    );

    expect($of(SiteDaemonAdvisor::SURFACE_QUEUE))->toBe(['horizon', 'queue'])
        ->and($of(SiteDaemonAdvisor::SURFACE_SCHEDULE))->toBe(['scheduler'])
        ->and($of(SiteDaemonAdvisor::SURFACE_WORKERS))->toBe(['reverb']);
});

test('an untagged suggestion falls back to Workers rather than vanishing', function () {
    // Workers is the catch-all: a new suggestion someone forgets to tag must
    // still be visible somewhere.
    expect(SiteDaemonAdvisor::onlyForSurface([['key' => 'mystery']], SiteDaemonAdvisor::SURFACE_WORKERS))
        ->toBe([['key' => 'mystery']])
        ->and(SiteDaemonAdvisor::onlyForSurface([['key' => 'mystery']], SiteDaemonAdvisor::SURFACE_QUEUE))
        ->toBe([]);
});
