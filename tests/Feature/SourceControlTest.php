<?php

namespace Tests\Feature\SourceControlTest;

use App\Livewire\Settings\SourceControl;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('source control page is reachable for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.source-control'))
        ->assertOk();
});

test('cannot unlink only sign in method without password', function () {
    $user = User::factory()->create(['password' => null]);
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-1',
        'nickname' => 'dev',
    ]);

    Livewire::actingAs($user)
        ->test(SourceControl::class)
        ->call('unlinkAccount', $user->socialAccounts->first()->id)
        ->assertHasErrors('unlink');
});

test('unlink account uses confirmation modal before deleting', function () {
    $user = User::factory()->create();
    $account = SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-1',
        'nickname' => 'dev',
    ]);

    Livewire::actingAs($user)
        ->test(SourceControl::class)
        ->call(
            'openConfirmActionModal',
            'unlinkAccount',
            [$account->id],
            'Unlink account',
            'Unlink this account? Deploy keys and webhooks for sites using this identity are unchanged.',
            'Unlink',
            true
        )
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'unlinkAccount')
        ->assertSet('confirmActionModalArguments', [$account->id]);

    $this->assertDatabaseHas('social_accounts', ['id' => $account->id]);

    Livewire::actingAs($user)
        ->test(SourceControl::class)
        ->call(
            'openConfirmActionModal',
            'unlinkAccount',
            [$account->id],
            'Unlink account',
            'Unlink this account? Deploy keys and webhooks for sites using this identity are unchanged.',
            'Unlink',
            true
        )
        ->call('confirmActionModal');

    $this->assertDatabaseMissing('social_accounts', ['id' => $account->id]);
});
