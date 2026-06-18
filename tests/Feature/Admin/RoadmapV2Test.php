<?php

declare(strict_types=1);

use App\Modules\Roadmap\Livewire\Admin\Index as AdminRoadmapIndex;
use App\Modules\Roadmap\Mail\RoadmapSuggestionStatusMail;
use App\Models\Organization;
use App\Models\RoadmapItem;
use App\Models\RoadmapRelease;
use App\Models\RoadmapSuggestion;
use App\Models\User;
use App\Modules\Roadmap\Support\RoadmapQuarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('roadmap quarter helper validates and labels keys', function () {
    expect(RoadmapQuarter::isValidKey('2026-Q3'))->toBeTrue()
        ->and(RoadmapQuarter::labelForKey('2026-Q3'))->toBe('Q3 2026')
        ->and(RoadmapQuarter::isValidKey('bad'))->toBeFalse();
});

test('promoting suggestion links roadmap item and sends email', function () {
    Mail::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $suggestion = RoadmapSuggestion::factory()->create([
        'title' => 'Add roadmap voting',
        'email' => 'voter@example.com',
    ]);

    Livewire::actingAs($user)
        ->test(AdminRoadmapIndex::class)
        ->call('openPromoteSuggestionModal', $suggestion->id)
        ->set('itemIsPublished', false)
        ->call('saveItem')
        ->assertHasNoErrors();

    $item = RoadmapItem::query()->where('title', 'Add roadmap voting')->first();
    expect($item)->not->toBeNull();

    $suggestion->refresh();
    expect($suggestion->promoted_roadmap_item_id)->toBe($item->id)
        ->and($suggestion->status)->toBe(RoadmapSuggestion::STATUS_REVIEWED);

    Mail::assertQueued(RoadmapSuggestionStatusMail::class, function (RoadmapSuggestionStatusMail $mail) use ($suggestion): bool {
        return $mail->hasTo($suggestion->email) && $mail->event === 'promoted';
    });
});

test('marking suggestion reviewed or declined sends status email', function () {
    Mail::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $reviewed = RoadmapSuggestion::factory()->create(['email' => 'reviewed@example.com']);
    $declined = RoadmapSuggestion::factory()->create(['email' => 'declined@example.com']);

    Livewire::actingAs($user)
        ->test(AdminRoadmapIndex::class)
        ->call('markSuggestionReviewed', $reviewed->id);

    Livewire::actingAs($user)
        ->test(AdminRoadmapIndex::class)
        ->call('markSuggestionDeclined', $declined->id);

    Mail::assertQueued(RoadmapSuggestionStatusMail::class, fn (RoadmapSuggestionStatusMail $mail): bool => $mail->hasTo('reviewed@example.com') && $mail->event === 'reviewed');
    Mail::assertQueued(RoadmapSuggestionStatusMail::class, fn (RoadmapSuggestionStatusMail $mail): bool => $mail->hasTo('declined@example.com') && $mail->event === 'declined');
});

test('admin can reorder items within a status column', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $first = RoadmapItem::factory()->create(['title' => 'First', 'status' => RoadmapItem::STATUS_PLANNED, 'sort_order' => 0]);
    $second = RoadmapItem::factory()->create(['title' => 'Second', 'status' => RoadmapItem::STATUS_PLANNED, 'sort_order' => 1]);

    Livewire::actingAs($user)
        ->test(AdminRoadmapIndex::class)
        ->call('reorderItems', RoadmapItem::STATUS_PLANNED, [$second->id, $first->id]);

    expect($first->fresh()?->sort_order)->toBe(1)
        ->and($second->fresh()?->sort_order)->toBe(0);
});

test('admin can drag roadmap item between status columns', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $planned = RoadmapItem::factory()->create(['title' => 'Planned item', 'status' => RoadmapItem::STATUS_PLANNED, 'sort_order' => 0]);
    $inProgress = RoadmapItem::factory()->create(['title' => 'Active item', 'status' => RoadmapItem::STATUS_IN_PROGRESS, 'sort_order' => 0]);

    Livewire::actingAs($user)
        ->test(AdminRoadmapIndex::class)
        ->call('syncRoadmapColumns', [
            RoadmapItem::STATUS_PLANNED => [],
            RoadmapItem::STATUS_IN_PROGRESS => [$planned->id, $inProgress->id],
            RoadmapItem::STATUS_SHIPPED => [],
        ]);

    expect($planned->fresh()?->status)->toBe(RoadmapItem::STATUS_IN_PROGRESS)
        ->and($planned->fresh()?->sort_order)->toBe(0)
        ->and($inProgress->fresh()?->sort_order)->toBe(1);
});

test('moving item to shipped sets shipped date and release train', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $release = RoadmapRelease::factory()->forSlug('2026-06')->create();
    $item = RoadmapItem::factory()->create([
        'status' => RoadmapItem::STATUS_IN_PROGRESS,
        'target_release_id' => $release->id,
        'shipped_at' => null,
        'shipped_release_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(AdminRoadmapIndex::class)
        ->call('syncRoadmapColumns', [
            RoadmapItem::STATUS_PLANNED => [],
            RoadmapItem::STATUS_IN_PROGRESS => [],
            RoadmapItem::STATUS_SHIPPED => [$item->id],
        ]);

    $item->refresh();
    expect($item->status)->toBe(RoadmapItem::STATUS_SHIPPED)
        ->and($item->shipped_at)->not->toBeNull()
        ->and($item->shipped_release_id)->toBe($release->id);
});
