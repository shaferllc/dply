<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Scaffold;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Scaffold\PlaceholderDnsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PR 8 — placeholder DNS assignment for scaffolded sites.
 *
 * Two paths verified end-to-end:
 *   - Configured DO zone → A record created via DigitalOcean API,
 *     hostname recorded under meta.scaffold.placeholder_dns
 *   - No zone configured → nip.io fallback, no API call, dashed-IP
 *     hostname recorded
 */
class PlaceholderDnsManagerTest extends TestCase
{
    use RefreshDatabase;

    private function makeSite(string $serverIp = '203.0.113.42', string $slug = 'my-blog'): Site
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

    public function test_falls_back_to_nip_io_when_no_zone_configured(): void
    {
        config(['scaffold_placeholder.zones' => []]);
        config(['scaffold_placeholder.default_zone' => 'ondply.io']);

        $site = $this->makeSite(serverIp: '203.0.113.42', slug: 'my-blog');

        $assignment = (new PlaceholderDnsManager())->assign($site);

        // nip.io's dashed-IP form is what their docs recommend.
        $this->assertSame('my-blog.203-0-113-42.nip.io', $assignment['hostname']);
        $this->assertSame('nip.io', $assignment['source']);
        $this->assertNull($assignment['zone']);

        $site->refresh();
        $this->assertSame('my-blog.203-0-113-42.nip.io',
            $site->meta['scaffold']['placeholder_dns']['hostname']);
    }

    public function test_assigns_via_digitalocean_api_when_zone_configured(): void
    {
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

        $site = $this->makeSite();
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

        $assignment = (new PlaceholderDnsManager())->assign($site);

        $this->assertSame('my-blog.ondply.io', $assignment['hostname']);
        $this->assertSame('ondply.io', $assignment['zone']);
        $this->assertSame('12345', $assignment['record_id']);
        $this->assertSame('dns_provider', $assignment['source']);
    }

    public function test_collision_appends_hash_suffix(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['domain_records' => []], 200);
            }

            return Http::response(['domain_record' => ['id' => 999]], 201);
        });

        $existing = $this->makeSite(slug: 'shared-name');
        $existing->meta = ['scaffold' => [
            'framework' => 'wordpress',
            'placeholder_dns' => ['hostname' => 'shared-name.ondply.io'],
        ]];
        $existing->save();

        $site = $this->makeSite(slug: 'shared-name');

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

        $assignment = (new PlaceholderDnsManager())->assign($site);

        // hash suffix appended → not the bare slug
        $this->assertNotSame('shared-name.ondply.io', $assignment['hostname']);
        $this->assertStringStartsWith('shared-name-', $assignment['hostname']);
        $this->assertStringEndsWith('.ondply.io', $assignment['hostname']);
    }

    public function test_assign_is_idempotent_when_already_assigned(): void
    {
        $site = $this->makeSite();
        $existing = ['hostname' => 'pre-assigned.ondply.io', 'zone' => 'ondply.io', 'record_id' => '1', 'source' => 'dns_provider'];
        $site->meta = ['scaffold' => ['framework' => 'wordpress', 'placeholder_dns' => $existing]];
        $site->save();

        $result = (new PlaceholderDnsManager())->assign($site);

        $this->assertSame('pre-assigned.ondply.io', $result['hostname']);
    }

    public function test_release_calls_dns_provider_delete_and_clears_meta(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/domains/ondply.io/records/12345' => Http::response(null, 204),
        ]);

        $site = $this->makeSite();
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

        (new PlaceholderDnsManager())->release($site);

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/records/12345') && $req->method() === 'DELETE');

        $site->refresh();
        $this->assertArrayNotHasKey('placeholder_dns', $site->meta['scaffold']);
    }

    public function test_release_is_a_noop_for_nip_io_assignment(): void
    {
        Http::fake();
        $site = $this->makeSite();
        $site->meta = ['scaffold' => [
            'framework' => 'wordpress',
            'placeholder_dns' => ['hostname' => 'x.1-2-3-4.nip.io', 'source' => 'nip.io'],
        ]];
        $site->save();

        (new PlaceholderDnsManager())->release($site);

        Http::assertNothingSent();

        $site->refresh();
        $this->assertArrayNotHasKey('placeholder_dns', $site->meta['scaffold']);
    }

    public function test_assign_throws_when_server_has_no_ip(): void
    {
        $site = $this->makeSite();
        $site->server->ip_address = null;
        $site->server->save();

        $this->expectExceptionMessage('no IP address');

        (new PlaceholderDnsManager())->assign($site);
    }
}
