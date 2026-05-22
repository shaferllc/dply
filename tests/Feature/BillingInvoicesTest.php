<?php


namespace Tests\Feature\BillingInvoicesTest;
use App\Livewire\Billing\Invoices;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guest cannot view invoices', function () {
    $org = Organization::factory()->create();

    $this->get(route('billing.invoices', $org))->assertRedirect();
});

test('org admin can view invoices page', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);

    Livewire::actingAs($user)
        ->test(Invoices::class, ['organization' => $org])
        ->assertOk()
        ->assertSee('Invoices');
});

test('org member cannot view invoices page', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);

    Livewire::actingAs($user)
        ->test(Invoices::class, ['organization' => $org])
        ->assertForbidden();
});