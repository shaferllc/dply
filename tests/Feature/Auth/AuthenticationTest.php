<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('submit')
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('submit')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_local_tj_account_can_authenticate_without_password(): void
    {
        config()->set('app.env', 'local');

        $user = User::factory()->create([
            'email' => 'tj@tjshafer.com',
        ]);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', '')
            ->call('submit')
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_local_tj_account_can_use_quick_login_button(): void
    {
        config()->set('app.env', 'local');

        $user = User::factory()->create([
            'email' => 'tj@tjshafer.com',
        ]);

        Livewire::test(Login::class)
            ->call('quickLogin')
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_local_quick_login_creates_tj_user_when_missing(): void
    {
        config()->set('app.env', 'local');

        $this->assertDatabaseMissing('users', ['email' => 'tj@tjshafer.com']);

        Livewire::test(Login::class)
            ->call('quickLogin')
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('users', ['email' => 'tj@tjshafer.com']);

        $user = User::query()->where('email', 'tj@tjshafer.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
