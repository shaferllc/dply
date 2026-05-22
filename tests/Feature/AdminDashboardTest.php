<?php


namespace Tests\Feature\AdminDashboardTest;
use App\Models\Organization;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guest cannot access platform admin', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login', absolute: false));
});

test('authenticated user can open platform admin in testing environment', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $this->actingAs($user)->get(route('admin.dashboard'))->assertOk()
        ->assertSee(__('Platform admin'))
        ->assertSee(__('Runtime & optimization'))
        ->assertSee('included (20)');
});