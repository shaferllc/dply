<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\SiteDaemonSuggestionDismissalTest;

use App\Models\Site;
use App\Support\Sites\SiteDaemonAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Dismissing a "Suggested processes" row hides it for the site and persists,
 * but is always recoverable — a dismissal must never be a one-way door.
 */
test('dismiss hides a suggestion key and persists it on the site', function (): void {
    $site = Site::factory()->create();

    expect(SiteDaemonAdvisor::dismissedKeys($site))->toBe([]);
    expect(SiteDaemonAdvisor::dismissedCount($site))->toBe(0);

    SiteDaemonAdvisor::dismiss($site, 'scheduler');

    $site->refresh();
    expect(SiteDaemonAdvisor::dismissedKeys($site))->toBe(['scheduler']);
    expect(SiteDaemonAdvisor::dismissedCount($site))->toBe(1);
    expect($site->meta['dismissed_daemon_suggestions'])->toBe(['scheduler']);
});

test('dismissing the same key twice does not duplicate it', function (): void {
    $site = Site::factory()->create();

    SiteDaemonAdvisor::dismiss($site, 'horizon');
    SiteDaemonAdvisor::dismiss($site->fresh(), 'horizon');

    expect(SiteDaemonAdvisor::dismissedKeys($site->fresh()))->toBe(['horizon']);
});

test('dismiss ignores a blank key', function (): void {
    $site = Site::factory()->create();

    SiteDaemonAdvisor::dismiss($site, '   ');

    expect(SiteDaemonAdvisor::dismissedKeys($site->fresh()))->toBe([]);
});

test('restoreAll clears every dismissal', function (): void {
    $site = Site::factory()->create();

    SiteDaemonAdvisor::dismiss($site, 'horizon');
    SiteDaemonAdvisor::dismiss($site->fresh(), 'reverb');
    expect(SiteDaemonAdvisor::dismissedCount($site->fresh()))->toBe(2);

    SiteDaemonAdvisor::restoreAll($site->fresh());

    $site->refresh();
    expect(SiteDaemonAdvisor::dismissedKeys($site))->toBe([]);
    expect($site->meta)->not->toHaveKey('dismissed_daemon_suggestions');
});

test('dismissals leave the rest of site meta intact', function (): void {
    $site = Site::factory()->create(['meta' => ['caching' => ['enabled' => true]]]);

    SiteDaemonAdvisor::dismiss($site, 'queue');

    $site->refresh();
    expect($site->meta['caching']['enabled'])->toBeTrue();
    expect($site->meta['dismissed_daemon_suggestions'])->toBe(['queue']);

    SiteDaemonAdvisor::restoreAll($site);

    $site->refresh();
    expect($site->meta['caching']['enabled'])->toBeTrue();
});

test('malformed dismissal meta degrades to no dismissals', function (): void {
    $site = Site::factory()->create(['meta' => ['dismissed_daemon_suggestions' => 'not-an-array']]);

    expect(SiteDaemonAdvisor::dismissedKeys($site))->toBe([]);
    expect(SiteDaemonAdvisor::dismissedCount($site))->toBe(0);
});
