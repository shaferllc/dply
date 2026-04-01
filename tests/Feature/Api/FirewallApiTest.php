<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\FirewallRuleTemplate;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerFirewallRule;
use App\Models\User;
use App\Services\Servers\ServerFirewallProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FirewallApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: Server, 2: string}
     */
    protected function orgServerAndToken(array $abilities = ['network.read', 'network.write']): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->ready()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'setup_status' => Server::SETUP_STATUS_DONE,
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        ]);
        ['plaintext' => $plain] = ApiToken::createToken($user, $org, 'firewall-test', null, $abilities);

        return [$org, $server, $plain];
    }

    public function test_firewall_show_returns_rules_templates_and_bundled_keys(): void
    {
        [$org, $server, $plain] = $this->orgServerAndToken(['network.read']);

        ServerFirewallRule::query()->create([
            'server_id' => $server->id,
            'name' => 'HTTPS',
            'port' => 443,
            'protocol' => 'tcp',
            'source' => 'any',
            'action' => 'allow',
            'enabled' => true,
            'sort_order' => 1,
        ]);

        $template = FirewallRuleTemplate::query()->create([
            'organization_id' => $org->id,
            'server_id' => null,
            'name' => 'Web basics',
            'description' => 'Starter rules',
            'rules' => [
                ['name' => 'HTTP', 'port' => 80, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
            ],
        ]);

        $response = $this->getJson('/api/v1/servers/'.$server->id.'/firewall', [
            'Authorization' => 'Bearer '.$plain,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.rules.0.name', 'HTTPS');
        $response->assertJsonPath('data.templates.0.id', $template->id);
        $response->assertJsonPath('data.bundled_template_keys.0', 'laravel_web');
    }

    public function test_firewall_apply_requires_ssh_ack_when_ssh_rule_is_not_explicitly_allowed(): void
    {
        [, $server, $plain] = $this->orgServerAndToken();

        $provisioner = Mockery::mock(ServerFirewallProvisioner::class);
        $provisioner->shouldReceive('sshAccessNotExplicitlyAllowed')->andReturn(true);
        $this->app->instance(ServerFirewallProvisioner::class, $provisioner);

        $this->postJson('/api/v1/servers/'.$server->id.'/firewall/apply', [], [
            'Authorization' => 'Bearer '.$plain,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'ssh_lockout_ack_required');
    }

    public function test_removed_advanced_firewall_endpoints_return_not_found(): void
    {
        [, $server, $plain] = $this->orgServerAndToken(['network.read', 'network.write']);

        $headers = ['Authorization' => 'Bearer '.$plain];

        $this->getJson('/api/v1/servers/'.$server->id.'/firewall/preview', $headers)->assertNotFound();
        $this->getJson('/api/v1/servers/'.$server->id.'/firewall/drift', $headers)->assertNotFound();
        $this->getJson('/api/v1/servers/'.$server->id.'/firewall/terraform', $headers)->assertNotFound();
        $this->getJson('/api/v1/servers/'.$server->id.'/firewall/iptables', $headers)->assertNotFound();
        $this->getJson('/api/v1/servers/'.$server->id.'/firewall/export', $headers)->assertNotFound();
        $this->postJson('/api/v1/servers/'.$server->id.'/firewall/import', [], $headers)->assertNotFound();
        $this->postJson('/api/v1/servers/'.$server->id.'/firewall/snapshots', [], $headers)->assertNotFound();
    }
}
