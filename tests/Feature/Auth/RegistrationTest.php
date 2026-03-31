<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Register;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        Livewire::test(Register::class)
            ->set('form.name', 'Test User')
            ->set('form.email', 'test@example.com')
            ->set('form.password', 'password')
            ->set('form.password_confirmation', 'password')
            ->call('submit')
            ->assertRedirect(route('verification.notice', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_registration_creates_a_default_workspace_organization(): void
    {
        Livewire::test(Register::class)
            ->set('form.name', 'Test User')
            ->set('form.email', 'test@example.com')
            ->set('form.password', 'password')
            ->set('form.password_confirmation', 'password')
            ->call('submit');

        $user = auth()->user();

        $this->assertNotNull($user);
        $this->assertDatabaseHas('organizations', [
            'name' => "Test User's Workspace",
        ]);

        $org = Organization::query()->where('name', "Test User's Workspace")->first();
        $this->assertNotNull($org);
        $this->assertTrue($org->hasMember($user));
        $this->assertSame($org->id, session('current_organization_id'));
    }
}
