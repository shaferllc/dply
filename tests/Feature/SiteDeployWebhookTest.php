<?php

namespace Tests\Feature;

use App\Jobs\RunSiteDeploymentJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\WebhookDeliveryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SiteDeployWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSiteWithSecret(string $secret = 'whsec_test_value'): Site
    {
        $org = Organization::factory()->create();
        $server = Server::factory()->create(['organization_id' => $org->id]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'webhook_secret' => $secret,
            'git_repository_url' => 'git@github.com:org/repo.git',
        ]);

        return $site->fresh();
    }

    public function test_rejects_when_secret_missing(): void
    {
        $org = Organization::factory()->create();
        $server = Server::factory()->create(['organization_id' => $org->id]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'webhook_secret' => null,
        ]);

        $this->postJson(route('hooks.site.deploy', $site))->assertStatus(400);
        $this->assertSame(1, WebhookDeliveryLog::query()->where('site_id', $site->id)->count());
    }

    public function test_rejects_invalid_signature(): void
    {
        $site = $this->makeSiteWithSecret();

        $this->postJson(route('hooks.site.deploy', $site), [], [
            'X-Dply-Signature' => 'sha256=deadbeef',
        ])->assertStatus(401);
    }

    public function test_accepts_legacy_body_hmac_and_queues_deploy(): void
    {
        Queue::fake();
        $site = $this->makeSiteWithSecret('plain_secret');
        $body = '';
        $sig = 'sha256='.hash_hmac('sha256', $body, 'plain_secret');

        $this->call('POST', route('hooks.site.deploy', $site), [], [], [], [
            'HTTP_X_DPLY_SIGNATURE' => $sig,
        ], $body)->assertStatus(202);

        Queue::assertPushed(RunSiteDeploymentJob::class);
    }

    public function test_accepts_timestamped_signature(): void
    {
        Queue::fake();
        $site = $this->makeSiteWithSecret('plain_secret');
        $body = '{"ref":"main"}';
        $ts = time();
        $sig = 'sha256='.hash_hmac('sha256', $ts.'.'.$body, 'plain_secret');

        $this->call('POST', route('hooks.site.deploy', $site), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DPLY_SIGNATURE' => $sig,
            'HTTP_X_DPLY_TIMESTAMP' => (string) $ts,
        ], $body)->assertStatus(202);

        Queue::assertPushed(RunSiteDeploymentJob::class);
    }

    public function test_rejects_disallowed_ip(): void
    {
        $site = $this->makeSiteWithSecret('plain_secret');
        $site->update(['webhook_allowed_ips' => ['203.0.113.50']]);

        $body = '';
        $sig = 'sha256='.hash_hmac('sha256', $body, 'plain_secret');

        $this->call('POST', route('hooks.site.deploy', $site), [], [], [], [
            'HTTP_X_DPLY_SIGNATURE' => $sig,
        ], $body)->assertStatus(403);
    }
}
