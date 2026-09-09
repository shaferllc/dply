<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Secrets\OrgSecretKeyManagerTest;

use App\Models\Organization;
use App\Models\OrgSecretKey;
use App\Models\Site;
use App\Models\SiteSecretResidency;
use App\Modules\Secrets\Services\OrgSecretKeyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('mint dply-held replaces a customer-held key', function () {
    $org = Organization::factory()->create();
    OrgSecretKey::query()->create([
        'organization_id' => $org->id,
        'public_recipient' => 'age1existingcustomerkey',
        'identity_holder' => OrgSecretKey::HOLDER_CUSTOMER,
        'dply_identity' => null,
        'fingerprint' => substr(hash('sha256', 'age1existingcustomerkey'), 0, 12),
    ]);

    $key = app(OrgSecretKeyManager::class)->mintDplyHeld($org->id);

    expect($key->identity_holder)->toBe(OrgSecretKey::HOLDER_DPLY)
        ->and($key->public_recipient)->toStartWith('age1')
        ->and($key->public_recipient)->not->toBe('age1existingcustomerkey')
        ->and($key->dplyCanDecrypt())->toBeTrue()
        ->and(OrgSecretKey::query()->where('organization_id', $org->id)->count())->toBe(1);
});

test('escrowed residency count is scoped to the org', function () {
    $org = Organization::factory()->create();
    $other = Organization::factory()->create();
    $site = Site::factory()->create(['organization_id' => $org->id]);
    $otherSite = Site::factory()->create(['organization_id' => $other->id]);

    SiteSecretResidency::query()->create([
        'site_id' => $site->id,
        'key' => 'APP_KEY',
        'mode' => SiteSecretResidency::MODE_ESCROW,
        'ciphertext' => 'age-blob',
    ]);
    SiteSecretResidency::query()->create([
        'site_id' => $otherSite->id,
        'key' => 'APP_KEY',
        'mode' => SiteSecretResidency::MODE_ESCROW,
        'ciphertext' => 'age-blob',
    ]);

    expect(app(OrgSecretKeyManager::class)->escrowedResidencyCount($org->id))->toBe(1);
});
