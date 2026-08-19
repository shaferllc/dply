<?php

declare(strict_types=1);

namespace Tests\Feature\Databases;

use App\Models\CloudDatabase;
use App\Models\CloudDatabaseTrustedSource;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Modules\Database\Services\TrustedSourceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * The app-server rule the provisioner installs. Every write must carry it
 * through — losing it cuts the live site off from its own database.
 */
const DROPLET_RULE = ['type' => 'droplet', 'value' => '145132'];

/** An address a customer added by hand in the provider console. */
const FOREIGN_RULE = ['type' => 'ip_addr', 'value' => '198.51.100.4'];

function trustedSourceCluster(array $existingRules = [DROPLET_RULE, FOREIGN_RULE]): CloudDatabase
{
    $org = Organization::factory()->create();

    $credential = ProviderCredential::factory()->create([
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
    ]);

    Http::fake([
        '*/databases/*/firewall' => Http::sequence()
            ->pushResponse(Http::response(['rules' => $existingRules], 200))
            ->pushResponse(Http::response([], 204))
            ->pushResponse(Http::response(['rules' => $existingRules], 200))
            ->pushResponse(Http::response([], 204))
            ->whenEmpty(Http::response(['rules' => $existingRules], 200)),
    ]);

    return CloudDatabase::factory()->active()->create([
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'backend' => CloudDatabase::BACKEND_DIGITALOCEAN,
        'backend_id' => 'do-cluster-1',
    ]);
}

/** @return list<array{type: string, value: string}> */
function lastWrittenRules(): array
{
    $written = [];

    foreach (Http::recorded() as [$request]) {
        /** @var Request $request */
        if ($request->method() === 'PUT' && str_contains($request->url(), '/firewall')) {
            $written = $request->data()['rules'] ?? [];
        }
    }

    return $written;
}

test('allowing an ip preserves the app server rule and foreign rules', function (): void {
    $database = trustedSourceCluster();
    $actor = User::factory()->create();

    app(TrustedSourceManager::class)->allow($database, '203.0.113.7', $actor);

    $written = lastWrittenRules();

    expect($written)->toContain(DROPLET_RULE)
        // A hand-added console rule is not dply's to remove.
        ->and($written)->toContain(FOREIGN_RULE)
        ->and($written)->toContain(['type' => 'ip_addr', 'value' => '203.0.113.7']);
});

test('the allowance is recorded with an expiry so it can be reaped', function (): void {
    $database = trustedSourceCluster();
    $actor = User::factory()->create();

    $record = app(TrustedSourceManager::class)->allow($database, '203.0.113.7', $actor);

    expect($record->expires_at->isFuture())->toBeTrue()
        ->and($record->created_by_user_id)->toBe($actor->id)
        ->and($record->isLive())->toBeTrue();
});

test('revoking removes only the dply entry', function (): void {
    $database = trustedSourceCluster();
    $actor = User::factory()->create();
    $manager = app(TrustedSourceManager::class);

    $record = $manager->allow($database, '203.0.113.7', $actor);
    $manager->revoke($record->fresh(), $actor);

    $written = lastWrittenRules();

    expect($written)->toContain(DROPLET_RULE)
        ->and($written)->toContain(FOREIGN_RULE)
        ->and($written)->not->toContain(['type' => 'ip_addr', 'value' => '203.0.113.7'])
        ->and($record->fresh()->revoked_at)->not->toBeNull();
});

test('the reaper strips expired entries but never foreign ones', function (): void {
    $database = trustedSourceCluster();
    $actor = User::factory()->create();

    CloudDatabaseTrustedSource::query()->create([
        'cloud_database_id' => $database->id,
        'ip_address' => '203.0.113.7',
        'created_by_user_id' => $actor->id,
        'expires_at' => now()->subMinute(),
    ]);

    $clusters = app(TrustedSourceManager::class)->reapExpired();

    $written = lastWrittenRules();

    expect($clusters)->toBe(1)
        ->and($written)->toContain(DROPLET_RULE)
        ->and($written)->toContain(FOREIGN_RULE)
        ->and($written)->not->toContain(['type' => 'ip_addr', 'value' => '203.0.113.7']);
});

test('a live allowance is left alone by the reaper', function (): void {
    $database = trustedSourceCluster();
    $actor = User::factory()->create();

    CloudDatabaseTrustedSource::query()->create([
        'cloud_database_id' => $database->id,
        'ip_address' => '203.0.113.7',
        'created_by_user_id' => $actor->id,
        'expires_at' => now()->addHours(4),
    ]);

    expect(app(TrustedSourceManager::class)->reapExpired())->toBe(0);
});

test('the kill switch blocks writes entirely', function (): void {
    config(['server_database.trusted_source_writes' => false]);

    $database = trustedSourceCluster();
    $actor = User::factory()->create();

    expect(fn () => app(TrustedSourceManager::class)->allow($database, '203.0.113.7', $actor))
        ->toThrow(\RuntimeException::class);

    expect(lastWrittenRules())->toBe([]);
});

test('backends without a trusted-source api are refused', function (): void {
    $database = trustedSourceCluster();
    $database->update(['backend' => CloudDatabase::BACKEND_NEON]);
    $actor = User::factory()->create();

    expect(app(TrustedSourceManager::class)->supports($database->fresh()))->toBeFalse()
        ->and(fn () => app(TrustedSourceManager::class)->allow($database->fresh(), '203.0.113.7', $actor))
        ->toThrow(\RuntimeException::class);
});

test('a malformed ip is refused before any provider call', function (): void {
    $database = trustedSourceCluster();
    $actor = User::factory()->create();

    expect(fn () => app(TrustedSourceManager::class)->allow($database, 'not-an-ip', $actor))
        ->toThrow(\RuntimeException::class);

    expect(lastWrittenRules())->toBe([]);
});
