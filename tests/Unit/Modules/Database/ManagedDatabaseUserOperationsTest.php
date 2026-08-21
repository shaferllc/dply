<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Database\ManagedDatabaseUserOperationsTest;

use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Modules\Database\Services\ManagedDatabaseBackups;
use App\Modules\Database\Services\ManagedDatabaseUsers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

uses(RefreshDatabase::class);

function clusterDatabase(array $overrides = []): CloudDatabase
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['api_token' => 'tok'],
    ]);

    return CloudDatabase::factory()->active()->create(array_merge([
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'backend_id' => 'do-users-1',
    ], $overrides));
}

test('deleting a user calls the provider', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/do-users-1/users/reporting' => Http::response([], 204),
    ]);

    app(ManagedDatabaseUsers::class)->delete(clusterDatabase(), 'reporting');

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/databases/do-users-1/users/reporting'));
});

test('the cluster admin cannot be deleted', function () {
    Http::fake();

    // The factory's connection block is issued for doadmin.
    expect(fn () => app(ManagedDatabaseUsers::class)->delete(clusterDatabase(), 'doadmin'))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

test('rotating a password returns the provider generated replacement', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/do-users-1/users/reporting/reset_auth' => Http::response([
            'user' => ['name' => 'reporting', 'role' => 'normal', 'password' => 'brand-new-pass'],
        ]),
    ]);

    $password = app(ManagedDatabaseUsers::class)->rotatePassword(clusterDatabase(), 'reporting');

    expect($password)->toBe('brand-new-pass');
});

test('a provider that returns no password is an error, not a silent empty string', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/do-users-1/users/reporting/reset_auth' => Http::response([
            'user' => ['name' => 'reporting', 'role' => 'normal'],
        ]),
    ]);

    expect(fn () => app(ManagedDatabaseUsers::class)->rotatePassword(clusterDatabase(), 'reporting'))
        ->toThrow(RuntimeException::class);
});

test('backups are listed newest first', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/do-users-1/backups' => Http::response([
            'database_backups' => [
                ['created_at' => '2026-08-18T04:00:00Z', 'size_gigabytes' => 0.5],
                ['created_at' => '2026-08-20T04:00:00Z', 'size_gigabytes' => 0.6],
                ['created_at' => '2026-08-19T04:00:00Z', 'size_gigabytes' => 0.55],
            ],
        ]),
    ]);

    $backups = app(ManagedDatabaseBackups::class)->list(clusterDatabase());

    expect(array_column($backups, 'created_at'))->toBe([
        '2026-08-20T04:00:00Z',
        '2026-08-19T04:00:00Z',
        '2026-08-18T04:00:00Z',
    ]);
});

test('a failing backups endpoint reports none rather than throwing', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/do-users-1/backups' => Http::response(['message' => 'boom'], 500),
    ]);

    expect(app(ManagedDatabaseBackups::class)->list(clusterDatabase()))->toBe([]);
});
