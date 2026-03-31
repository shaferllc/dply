<?php

namespace Tests\Feature;

use App\Livewire\Profile\DeleteAccount;
use App\Livewire\Profile\Edit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_security_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/profile/security')
            ->assertOk()
            ->assertSee('Security', false);
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Edit::class)
            ->set('profileForm.name', 'Test User')
            ->set('profileForm.email', 'test@example.com')
            ->call('updateProfile')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Edit::class)
            ->set('profileForm.name', 'Test User')
            ->set('profileForm.email', $user->email)
            ->call('updateProfile')
            ->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_billing_details_can_be_updated(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Edit::class)
            ->set('billingForm.invoice_email', 'billing@example.com')
            ->set('billingForm.vat_number', 'NL123456789B01')
            ->set('billingForm.billing_currency', 'EUR')
            ->set('billingForm.billing_details', "Acme Co.\n123 Main St")
            ->call('updateBilling')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('billing@example.com', $user->invoice_email);
        $this->assertSame('NL123456789B01', $user->vat_number);
        $this->assertSame('EUR', $user->billing_currency);
        $this->assertSame("Acme Co.\n123 Main St", $user->billing_details);
    }

    public function test_delete_account_page_is_displayed_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile/delete-account');

        $response->assertOk();
    }

    public function test_delete_account_page_redirects_guests(): void
    {
        $response = $this->get('/profile/delete-account');

        $response->assertRedirect(route('login', absolute: false));
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(DeleteAccount::class)
            ->set('delete_password', 'password')
            ->call('deleteAccount')
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(DeleteAccount::class)
            ->set('delete_password', 'wrong-password')
            ->call('deleteAccount')
            ->assertHasErrors(['delete_password']);

        $this->assertNotNull($user->fresh());
    }
}
