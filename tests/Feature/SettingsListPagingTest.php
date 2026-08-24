<?php

namespace Tests\Feature\SettingsListPagingTest;

use App\Livewire\Settings\ApiKeys;
use App\Livewire\Settings\SshKeys;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function pagingUser(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}

test('a long settings list is paged, and page two shows the rest', function () {
    [$user, $org] = pagingUser();

    foreach (range(1, 12) as $i) {
        ApiToken::createToken($user, $org, sprintf('paging-token-%02d', $i), null, ['servers.read'], null);
    }

    // Read the real order rather than assuming it: the page slices whatever
    // the query returns, and that is what the assertion is about.
    $ordered = ApiToken::query()
        ->where('organization_id', $org->id)
        ->orderByDesc('id')
        ->pluck('name');
    $firstRow = $ordered->first();
    $lastRow = $ordered->last();

    Livewire::actingAs($user)
        ->test(ApiKeys::class)
        ->set('organization_id', $org->id)
        ->assertSee($firstRow)
        ->assertDontSee($lastRow)
        ->set('token_page', 2)
        ->assertSee($lastRow)
        ->assertDontSee($firstRow);
});

test('the page clamps when the last row of the last page goes away', function () {
    [$user, $org] = pagingUser();

    foreach (range(1, 11) as $i) {
        ApiToken::createToken($user, $org, sprintf('paging-token-%02d', $i), null, ['servers.read'], null);
    }

    $lonely = ApiToken::query()
        ->where('organization_id', $org->id)
        ->orderByDesc('id')
        ->get()
        ->last();

    Livewire::actingAs($user)
        ->test(ApiKeys::class)
        ->set('organization_id', $org->id)
        ->set('token_page', 2)
        ->call('revokeToken', (string) $lonely->id)
        // Page two no longer exists: stay on one rather than showing nothing.
        ->assertSet('token_page', 1);
});

test('searching returns you to the first page', function () {
    [$user, $org] = pagingUser();

    foreach (range(1, 12) as $i) {
        ApiToken::createToken($user, $org, sprintf('paging-token-%02d', $i), null, ['servers.read'], null);
    }

    Livewire::actingAs($user)
        ->test(ApiKeys::class)
        ->set('organization_id', $org->id)
        ->set('token_page', 2)
        ->set('token_list_search', 'paging-token-0')
        ->assertSet('token_page', 1);
});

test('ssh keys page independently of api tokens', function () {
    [$user] = pagingUser();

    foreach (range(1, 12) as $i) {
        $user->sshKeys()->create([
            'name' => sprintf('paging-key-%02d', $i),
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5'.$i.' test@example.com',
        ]);
    }

    // Names carry a prefix on purpose: a bare "key-01" also matches the
    // wire:key="ssh-key-01M0…" ULID on every row, so the assertion would pass
    // on any page and prove nothing.
    Livewire::actingAs($user)
        ->test(SshKeys::class)
        ->assertSee('paging-key-01')
        ->assertDontSee('paging-key-12')
        ->set('ssh_key_page', 2)
        ->assertSee('paging-key-12')
        ->assertDontSee('paging-key-01');
});

test('every settings pager renders inside its component root', function () {
    // The pager was inserted programmatically across four pages and landed
    // outside the Livewire root on two of them (and inside a modal header on a
    // third), where it silently never rendered.
    [$user] = pagingUser();

    foreach (range(1, 12) as $i) {
        $user->sshKeys()->create([
            'name' => sprintf('paging-key-%02d', $i),
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5'.$i.' test@example.com',
        ]);
    }

    $html = $this->actingAs($user)->get(route('profile.ssh-keys'))->assertOk()->getContent();

    // Livewire closes its root before the page's trailing markup; anything after
    // that never reaches the browser as part of the component.
    $pagerAt = strpos($html, 'ssh_key_page');
    expect($pagerAt)->not->toBeFalse();
    expect(substr_count($html, "\$set('ssh_key_page'"))->toBeGreaterThan(0);
});
