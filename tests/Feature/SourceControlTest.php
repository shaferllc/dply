<?php

namespace Tests\Feature;

use App\Livewire\Settings\SourceControl;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SourceControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_control_page_is_reachable_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.source-control'))
            ->assertOk();
    }

    public function test_cannot_unlink_only_sign_in_method_without_password(): void
    {
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
    }
}
