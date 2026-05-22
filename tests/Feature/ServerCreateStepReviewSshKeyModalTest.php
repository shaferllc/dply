<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Servers\Create\StepReview;
use App\Models\Organization;
use App\Models\ServerCreateDraft;
use App\Models\User;
use App\Models\UserSshKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Step 4 review page renders a preflight panel that includes
 * "Add SSH key" buttons. Those buttons dispatch open-modal for
 * personal-ssh-key-modal — so the modal listener has to live on
 * the same page. Until 2026-05 it didn't, and clicks vanished
 * into a void. Lock it in.
 */
class ServerCreateStepReviewSshKeyModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_step_review_renders_personal_ssh_key_modal_listener(): void
    {
        $user = $this->seedUserWithDraftAtReview();

        $response = $this->actingAs($user)->get(route('servers.create.review'));

        $response->assertOk();
        // The modal renders inside <x-modal name="personal-ssh-key-modal">,
        // which translates to a wire:key/Alpine x-data block carrying that
        // name string. The form heading "Add a personal SSH key" is unique
        // to that modal — assert it's present.
        $response->assertSee('Add a personal SSH key');
    }

    public function test_preflight_refreshes_when_personal_ssh_key_event_dispatches(): void
    {
        $user = $this->seedUserWithDraftAtReview();
        // No keys → blocker should be present on first render.
        $component = Livewire::actingAs($user)
            ->test(StepReview::class);
        $component->assertSee('Add a personal profile SSH key');

        // Now create a key out-of-band (simulating what the
        // PersonalSshKeyModal does when it persists) and dispatch
        // the event the modal would dispatch on save.
        UserSshKey::factory()->create([
            'user_id' => $user->id,
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('z', 43).' refresh-test',
            'provision_on_new_servers' => true,
        ]);

        $component->dispatch('personal-ssh-key-created')
            ->assertDontSee('Add a personal profile SSH key');
    }

    public function test_preflight_add_ssh_key_button_targets_personal_ssh_key_modal(): void
    {
        $user = $this->seedUserWithDraftAtReview();

        $response = $this->actingAs($user)->get(route('servers.create.review'));

        $response->assertOk();
        // Click handler dispatches the open-modal Livewire event with
        // 'personal-ssh-key-modal' as the target. Assert the dispatch
        // string is present so we'd notice if the modal name drifts.
        $response->assertSee("'open-modal', 'personal-ssh-key-modal'", false);
    }

    private function seedUserWithDraftAtReview(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        ServerCreateDraft::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'step' => 4,
            'payload' => [
                'mode' => 'provider',
                'type' => 'digitalocean',
                'name' => 'review-test',
                'install_profile' => 'laravel_app',
                'server_role' => 'application',
                'webserver' => 'nginx',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
                'region' => 'nyc1',
                'size' => 's-1vcpu-1gb',
            ],
        ]);

        return $user;
    }
}
