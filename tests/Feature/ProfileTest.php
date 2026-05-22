<?php


namespace Tests\Feature\ProfileTest;
use App\Livewire\Profile\DeleteAccount;
use App\Livewire\Profile\Edit;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile edit shows dashboard profile breadcrumb', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSeeText('Dashboard')
        ->assertSeeText('Profile')
        ->assertSee('aria-current="page"', false);
});

test('security page is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/profile/security')
        ->assertOk()
        ->assertSee('Security', false);
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Edit::class)
        ->set('profileForm.name', 'Test User')
        ->set('profileForm.email', 'test@example.com')
        ->call('updateProfile')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: __('Profile details saved.'), type: 'success');

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Edit::class)
        ->set('profileForm.name', 'Test User')
        ->set('profileForm.email', $user->email)
        ->call('updateProfile')
        ->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('billing details can be updated', function () {
    Http::fake([
        'ec.europa.eu/*' => Http::response(
            '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soap:Body><checkVatResponse><valid>true</valid></checkVatResponse></soap:Body></soap:Envelope>',
            200,
            ['Content-Type' => 'text/xml']
        ),
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Edit::class)
        ->set('billingForm.invoice_email', 'billing@example.com')
        ->set('billingForm.vat_number', 'NL123456789B01')
        ->set('billingForm.billing_currency', 'EUR')
        ->set('billingForm.billing_details', "Acme Co.\n123 Main St")
        ->call('updateBilling')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: __('Billing details saved.'), type: 'success');

    $user->refresh();

    expect($user->invoice_email)->toBe('billing@example.com');
    expect($user->vat_number)->toBe('NL123456789B01');
    expect($user->billing_currency)->toBe('EUR');
    expect($user->billing_details)->toBe("Acme Co.\n123 Main St");
});

test('billing vat number must match supported format', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Edit::class)
        ->set('billingForm.vat_number', 'weewrewerwrewrweew')
        ->call('updateBilling')
        ->assertHasErrors(['billingForm.vat_number']);

    expect($user->refresh()->vat_number)->toBeNull();
});

test('delete account page is displayed for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/profile/delete-account');

    $response->assertOk();
});

test('delete account page redirects guests', function () {
    $response = $this->get('/profile/delete-account');

    $response->assertRedirect(route('login', absolute: false));
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DeleteAccount::class)
        ->set('delete_password', 'password')
        ->call('deleteAccount')
        ->assertRedirect('/');

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DeleteAccount::class)
        ->set('delete_password', 'wrong-password')
        ->call('deleteAccount')
        ->assertHasErrors(['delete_password']);

    expect($user->fresh())->not->toBeNull();
});