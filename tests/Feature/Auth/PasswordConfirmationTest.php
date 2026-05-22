<?php


namespace Tests\Feature\Auth\PasswordConfirmationTest;
use App\Livewire\Auth\ConfirmPassword;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/confirm-password');

    $response->assertStatus(200);
});

test('password can be confirmed', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ConfirmPassword::class)
        ->set('password', 'password')
        ->call('submit')
        ->assertRedirect();
});

test('password is not confirmed with invalid password', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ConfirmPassword::class)
        ->set('password', 'wrong-password')
        ->call('submit')
        ->assertHasErrors('password');
});