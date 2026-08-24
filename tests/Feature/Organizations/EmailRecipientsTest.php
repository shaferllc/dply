<?php

namespace Tests\Feature\Organizations\EmailRecipientsTest;

use App\Livewire\Organizations\Settings;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create();

    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();
    $this->creator = User::factory()->create();

    $this->org->users()->attach($this->owner->id, ['role' => 'owner']);
    $this->org->users()->attach($this->admin->id, ['role' => 'admin']);
    $this->org->users()->attach($this->member->id, ['role' => 'member']);
    $this->org->users()->attach($this->creator->id, ['role' => 'member']);
});

test('defaults preserve the behaviour each send-site used to hardcode', function () {
    // Deploy fanned out to owners and admins; the credential emails did not.
    expect($this->org->emailRecipientMode(Organization::EMAIL_DEPLOY))
        ->toBe(Organization::RECIPIENTS_ADMINS);
    expect($this->org->emailRecipientMode(Organization::EMAIL_SERVER_CREDENTIALS))
        ->toBe(Organization::RECIPIENTS_CREATOR);
    expect($this->org->emailRecipientMode(Organization::EMAIL_DATABASE_CREDENTIALS))
        ->toBe(Organization::RECIPIENTS_CREATOR);
});

test('creator mode sends to the creator alone', function () {
    $recipients = $this->org->emailRecipients(
        Organization::EMAIL_SERVER_CREDENTIALS,
        $this->creator,
    );

    expect($recipients->pluck('id')->all())->toBe([$this->creator->id]);
});

test('admins mode adds every owner and admin to the creator', function () {
    $ids = $this->org->emailRecipients(Organization::EMAIL_DEPLOY, $this->creator)
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($ids)->toEqualCanonicalizing([
        $this->creator->id,
        $this->owner->id,
        $this->admin->id,
    ]);
    expect($ids)->not->toContain($this->member->id);
});

test('custom mode adds the chosen members and always keeps the creator', function () {
    $this->org->update(['email_recipient_prefs' => [
        Organization::EMAIL_DATABASE_CREDENTIALS => [
            'mode' => Organization::RECIPIENTS_CUSTOM,
            'user_ids' => [$this->member->id],
        ],
    ]]);

    $ids = $this->org->fresh()
        ->emailRecipients(Organization::EMAIL_DATABASE_CREDENTIALS, $this->creator)
        ->pluck('id')
        ->all();

    expect($ids)->toEqualCanonicalizing([$this->creator->id, $this->member->id]);
    expect($ids)->not->toContain($this->owner->id);
});

test('a chosen recipient who leaves the organization stops receiving secrets', function () {
    $this->org->update(['email_recipient_prefs' => [
        Organization::EMAIL_DATABASE_CREDENTIALS => [
            'mode' => Organization::RECIPIENTS_CUSTOM,
            'user_ids' => [$this->member->id],
        ],
    ]]);

    // The stored id survives the departure; membership is what gates delivery.
    $this->org->users()->detach($this->member->id);

    $ids = $this->org->fresh()
        ->emailRecipients(Organization::EMAIL_DATABASE_CREDENTIALS, $this->creator)
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$this->creator->id]);
});

test('a non-member id can never be stored as a recipient', function () {
    $outsider = User::factory()->create();

    Livewire::actingAs($this->owner)
        ->test(Settings::class, ['organization' => $this->org])
        ->set('email_recipient_modes.database_credentials', Organization::RECIPIENTS_CUSTOM)
        ->set('email_recipient_user_ids.database_credentials', [$outsider->id, $this->member->id])
        ->call('saveEmailRecipients', 'database_credentials');

    expect($this->org->fresh()->emailRecipientUserIds(Organization::EMAIL_DATABASE_CREDENTIALS))
        ->toBe([(string) $this->member->id]);
});

test('an unknown mode falls back to the default rather than sending to nobody', function () {
    $this->org->update(['email_recipient_prefs' => [
        Organization::EMAIL_DEPLOY => ['mode' => 'everyone-on-the-internet', 'user_ids' => []],
    ]]);

    expect($this->org->fresh()->emailRecipientMode(Organization::EMAIL_DEPLOY))
        ->toBe(Organization::RECIPIENTS_ADMINS);
});
