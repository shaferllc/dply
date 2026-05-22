<?php


namespace Tests\Feature\Auth\AuthenticationTest;
use App\Livewire\Auth\Login;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('submit')
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'wrong-password')
        ->call('submit')
        ->assertHasErrors('email');

    $this->assertGuest();
});

test('local tj account can authenticate without password', function () {
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
});

test('local tj account can use quick login button', function () {
    config()->set('app.env', 'local');

    $user = User::factory()->create([
        'email' => 'tj@tjshafer.com',
    ]);

    Livewire::test(Login::class)
        ->call('quickLogin')
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('local quick login creates tj user when missing', function () {
    config()->set('app.env', 'local');

    $this->assertDatabaseMissing('users', ['email' => 'tj@tjshafer.com']);

    Livewire::test(Login::class)
        ->call('quickLogin')
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertDatabaseHas('users', ['email' => 'tj@tjshafer.com']);

    $user = User::query()->where('email', 'tj@tjshafer.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});