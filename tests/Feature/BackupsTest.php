<?php

namespace Tests\Feature;

use App\Livewire\Backups\Databases;
use App\Livewire\Backups\Files;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BackupsTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_guest_cannot_view_backups(): void
    {
        $this->get('/backups/databases')->assertRedirect();
        $this->get('/backups/files')->assertRedirect();
    }

    public function test_backups_redirects_to_databases(): void
    {
        $user = $this->userWithOrganization();

        $this->actingAs($user)
            ->get('/backups')
            ->assertRedirect('/backups/databases');
    }

    public function test_authenticated_user_can_view_database_backups_page(): void
    {
        $user = $this->userWithOrganization();

        $this->actingAs($user)
            ->get(route('backups.databases'))
            ->assertOk()
            ->assertSee('Database backups', false);
    }

    public function test_authenticated_user_can_view_file_backups_page(): void
    {
        $user = $this->userWithOrganization();

        $this->actingAs($user)
            ->get(route('backups.files'))
            ->assertOk()
            ->assertSee('File backups', false);
    }

    public function test_backups_livewire_components_render(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(Databases::class)
            ->assertOk();

        Livewire::actingAs($user)
            ->test(Files::class)
            ->assertOk();
    }
}
