<?php

declare(strict_types=1);

use App\Http\Middleware\RedirectGuestsToComingSoon;
use App\Modules\Roadmap\Livewire\Admin\Index as AdminRoadmapIndex;
use App\Models\Organization;
use App\Modules\Roadmap\Models\RoadmapItem;
use App\Models\RoadmapRelease;
use App\Models\User;
use App\Modules\Roadmap\Support\RoadmapReleaseTrain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('roadmap release train slug validates and labels correctly', function () {
    expect(RoadmapReleaseTrain::isValidSlug('2026-06'))->toBeTrue()
        ->and(RoadmapReleaseTrain::trainLabel('2026-06'))->toBe('Release 2026.06')
        ->and(RoadmapReleaseTrain::monthLabel('2026-06'))->toBe('June 2026');
});

test('admin can create release train and assign to roadmap item', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(AdminRoadmapIndex::class)
        ->set('tab', 'releases')
        ->call('openCreateReleaseModal')
        ->set('releaseSlug', '2026-08')
        ->set('releaseSummary', 'August platform updates.')
        ->set('releaseIsPublished', true)
        ->call('saveRelease')
        ->assertHasNoErrors();

    $release = RoadmapRelease::query()->where('slug', '2026-08')->first();
    expect($release)->not->toBeNull();

    Livewire::actingAs($user)
        ->test(AdminRoadmapIndex::class)
        ->call('openCreateItemModal')
        ->set('itemTitle', 'August feature')
        ->set('itemTargetReleaseId', $release->id)
        ->set('itemIsPublished', true)
        ->call('saveItem');

    $item = RoadmapItem::query()->where('title', 'August feature')->first();
    expect($item?->target_release_id)->toBe($release->id);
});

test('shipped item auto-assigns shipped release from target release', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $release = RoadmapRelease::factory()->forSlug('2026-06')->create(['is_published' => true]);

    Livewire::actingAs($user)
        ->test(AdminRoadmapIndex::class)
        ->call('openCreateItemModal')
        ->set('itemTitle', 'Shipped in June train')
        ->set('itemStatus', RoadmapItem::STATUS_SHIPPED)
        ->set('itemTargetReleaseId', $release->id)
        ->set('itemIsPublished', true)
        ->call('saveItem');

    $item = RoadmapItem::query()->where('title', 'Shipped in June train')->first();
    expect($item?->shipped_release_id)->toBe($release->id)
        ->and($item?->shipped_at)->not->toBeNull();
});

test('public roadmap shows release train filter and timeline', function () {
    $release = RoadmapRelease::factory()->forSlug('2026-05')->create([
        'is_published' => true,
        'summary' => 'May release notes.',
    ]);

    RoadmapItem::factory()->shipped()->create([
        'title' => 'May shipped feature',
        'is_published' => true,
        'shipped_release_id' => $release->id,
        'shipped_at' => '2026-05-20',
    ]);

    $this->withoutMiddleware([RedirectGuestsToComingSoon::class])
        ->get(route('roadmap'))
        ->assertOk()
        ->assertSee('Release 2026.05')
        ->assertSee('Release history')
        ->assertSee('May shipped feature');
});
