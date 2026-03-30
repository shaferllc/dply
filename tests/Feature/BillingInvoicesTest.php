<?php

namespace Tests\Feature;

use App\Livewire\Billing\Invoices;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BillingInvoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_invoices(): void
    {
        $org = Organization::factory()->create();

        $this->get(route('billing.invoices', $org))->assertRedirect();
    }

    public function test_org_admin_can_view_invoices_page(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);

        Livewire::actingAs($user)
            ->test(Invoices::class, ['organization' => $org])
            ->assertOk()
            ->assertSee('Invoices');
    }

    public function test_org_member_cannot_view_invoices_page(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);

        Livewire::actingAs($user)
            ->test(Invoices::class, ['organization' => $org])
            ->assertForbidden();
    }
}
