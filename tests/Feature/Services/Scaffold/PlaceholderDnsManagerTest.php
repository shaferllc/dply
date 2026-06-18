<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Scaffold\PlaceholderDnsManagerTest;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Scaffold\Services\PlaceholderDnsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeSite(string $serverIp = '203.0.113.42', string $slug = 'my-blog'): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ip_address' => $serverIp,
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => $slug,
        'meta' => ['scaffold' => ['framework' => 'wordpress']],
    ]);
}
test('falls back to nip io when no zone configured', function () {
    config(['scaffold_placeholder.zones' => []]);
    config(['scaffold_placeholder.default_zone' => 'ondply.io']);

    $site = makeSite(serverIp: '203.0.113.42', slug: 'my-blog');

    $assignment = (new PlaceholderDnsManager)->assign($site);

    // nip.io's dashed-IP form is what their docs recommend.
    expect($assignment['hostname'])->toBe('my-blog.203-0-113-42.nip.io');
    expect($assignment['source'])->toBe('nip.io');
    expect($assignment['zone'])->toBeNull();

    $site->refresh();
    expect($site->meta['scaffold']['placeholder_dns']['hostname'])->toBe('my-blog.203-0-113-42.nip.io');
});
test('assigns via digitalocean api when zone configured', function () {
    Http::fake(function ($request) {
        // findDomainRecord queries with GET (one or two passes); empty
        // result triggers the create POST. Branch by HTTP verb to
        // simulate "no existing record, then create succeeds".
        if ($request->method() === 'GET') {
            return Http::response(['domain_records' => []], 200);
        }
        if ($request->method() === 'POST') {
            return Http::response(['domain_record' => ['id' => 12345, 'type' => 'A', 'name' => 'my-blog']], 201);
        }

        return Http::response(null, 204);
    });

    $site = makeSite();
    $credential = ProviderCredential::query()->create([
        'user_id' => $site->user_id,
        'organization_id' => $site->organization_id,
        'provider' => 'digitalocean',
        'name' => 'platform-default',
        'credentials' => ['api_token' => 't'],
    ]);

    config([
        'scaffold_placeholder.zones' => [
            'ondply.io' => ['provider' => 'digitalocean', 'credential_id' => $credential->id],
        ],
        'scaffold_placeholder.default_zone' => 'ondply.io',
    ]);

    $assignment = (new PlaceholderDnsManager)->assign($site);

    expect($assignment['hostname'])->toBe('my-blog.ondply.io');
    expect($assignment['zone'])->toBe('ondply.io');
    expect($assignment['record_id'])->toBe('12345');
    expect($assignment['source'])->toBe('dns_provider');
});
test('collision appends hash suffix', function () {
    Http::fake(function ($request) {
        if ($request->method() === 'GET') {
            return Http::response(['domain_records' => []], 200);
        }

        return Http::response(['domain_record' => ['id' => 999]], 201);
    });

    $existing = makeSite(slug: 'shared-name');
    $existing->meta = ['scaffold' => [
        'framework' => 'wordpress',
        'placeholder_dns' => ['hostname' => 'shared-name.ondply.io'],
    ]];
    $existing->save();

    $site = makeSite(slug: 'shared-name');

    $credential = ProviderCredential::query()->create([
        'user_id' => $site->user_id,
        'organization_id' => $site->organization_id,
        'provider' => 'digitalocean',
        'name' => 'p',
        'credentials' => ['api_token' => 't'],
    ]);
    config([
        'scaffold_placeholder.zones' => ['ondply.io' => ['provider' => 'digitalocean', 'credential_id' => $credential->id]],
        'scaffold_placeholder.default_zone' => 'ondply.io',
    ]);

    $assignment = (new PlaceholderDnsManager)->assign($site);

    // hash suffix appended → not the bare slug
    $this->assertNotSame('shared-name.ondply.io', $assignment['hostname']);
    expect($assignment['hostname'])->toStartWith('shared-name-');
    expect($assignment['hostname'])->toEndWith('.ondply.io');
});
test('assign is idempotent when already assigned', function () {
    $site = makeSite();
    $existing = ['hostname' => 'pre-assigned.ondply.io', 'zone' => 'ondply.io', 'record_id' => '1', 'source' => 'dns_provider'];
    $site->meta = ['scaffold' => ['framework' => 'wordpress', 'placeholder_dns' => $existing]];
    $site->save();

    $result = (new PlaceholderDnsManager)->assign($site);

    expect($result['hostname'])->toBe('pre-assigned.ondply.io');
});
test('release calls dns provider delete and clears meta', function () {
    Http::fake([
        'api.digitalocean.com/v2/domains/ondply.io/records/12345' => Http::response(null, 204),
    ]);

    $site = makeSite();
    $credential = ProviderCredential::query()->create([
        'user_id' => $site->user_id,
        'organization_id' => $site->organization_id,
        'provider' => 'digitalocean',
        'name' => 'p',
        'credentials' => ['api_token' => 't'],
    ]);
    config([
        'scaffold_placeholder.zones' => ['ondply.io' => ['provider' => 'digitalocean', 'credential_id' => $credential->id]],
        'scaffold_placeholder.default_zone' => 'ondply.io',
    ]);
    $site->meta = ['scaffold' => [
        'framework' => 'wordpress',
        'placeholder_dns' => ['hostname' => 'x.ondply.io', 'zone' => 'ondply.io', 'record_id' => '12345', 'source' => 'dns_provider'],
    ]];
    $site->save();

    (new PlaceholderDnsManager)->release($site);

    Http::assertSent(fn ($req) => str_ends_with($req->url(), '/records/12345') && $req->method() === 'DELETE');

    $site->refresh();
    $this->assertArrayNotHasKey('placeholder_dns', $site->meta['scaffold']);
});
test('release is a noop for nip io assignment', function () {
    Http::fake();
    $site = makeSite();
    $site->meta = ['scaffold' => [
        'framework' => 'wordpress',
        'placeholder_dns' => ['hostname' => 'x.1-2-3-4.nip.io', 'source' => 'nip.io'],
    ]];
    $site->save();

    (new PlaceholderDnsManager)->release($site);

    Http::assertNothingSent();

    $site->refresh();
    $this->assertArrayNotHasKey('placeholder_dns', $site->meta['scaffold']);
});
test('assign throws when server has no ip', function () {
    $site = makeSite();
    $site->server->ip_address = null;
    $site->server->save();

    $this->expectExceptionMessage('no IP address');

    (new PlaceholderDnsManager)->assign($site);
});
