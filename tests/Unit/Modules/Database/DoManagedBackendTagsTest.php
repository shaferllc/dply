<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Database\DoManagedBackendTagsTest;

use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Database\Backends\DoManagedBackend;
use App\Support\Servers\ProviderResourceTags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('managed provision sends dply server and site tags', function () {
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
    ]);
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $server->user_id,
    ]);
    $database = CloudDatabase::factory()->create([
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'engine' => CloudDatabase::ENGINE_POSTGRES,
        'meta' => ['provisioned_for_site_id' => (string) $site->id],
    ]);

    Http::fake([
        'https://api.digitalocean.com/v2/databases/options*' => Http::response(['options' => []], 200),
        'https://api.digitalocean.com/v2/databases' => Http::response([
            'database' => [
                'id' => 'db-tagged',
                'status' => 'creating',
                'engine' => 'pg',
                'connection' => [],
            ],
        ], 201),
    ]);

    (new DoManagedBackend)->provision($database);

    $expected = ProviderResourceTags::forManagedDatabase($server, $site);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/databases')
        && $request['tags'] === $expected);

    expect($database->fresh()?->backend_id)->toBe('db-tagged');
});
