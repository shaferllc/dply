<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\RedeployCloudSiteJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CloudEnvCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Use the fake backend so backend->updateEnvVars no-ops without HTTP.
        config(['server_provision_fake.env_flag' => true]);
    }

    public function test_set_merges_keys_into_existing_env(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite("APP_ENV=staging\nLOG_LEVEL=info\n");

        $exit = Artisan::call('dply:cloud:env', [
            'site' => $site->name,
            '--set' => ['LOG_LEVEL=debug', 'NEW_KEY=value'],
        ]);

        $this->assertSame(0, $exit);
        $fresh = $site->fresh();
        $this->assertStringContainsString('APP_ENV=staging', $fresh->env_file_content);
        $this->assertStringContainsString('LOG_LEVEL=debug', $fresh->env_file_content);
        $this->assertStringContainsString('NEW_KEY=value', $fresh->env_file_content);
        $this->assertStringNotContainsString('LOG_LEVEL=info', $fresh->env_file_content);
        Queue::assertPushed(RedeployCloudSiteJob::class);
    }

    public function test_file_replaces_env_content(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite("OLD=1\n");
        $tmp = tempnam(sys_get_temp_dir(), 'dply-env-');
        file_put_contents($tmp, "FRESH=2\nOTHER=3\n");

        try {
            $exit = Artisan::call('dply:cloud:env', [
                'site' => $site->name,
                '--file' => $tmp,
            ]);
        } finally {
            @unlink($tmp);
        }

        $this->assertSame(0, $exit);
        $fresh = $site->fresh();
        $this->assertStringContainsString('FRESH=2', $fresh->env_file_content);
        $this->assertStringNotContainsString('OLD=1', $fresh->env_file_content);
    }

    public function test_no_redeploy_flag_skips_dispatch(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite('');

        $exit = Artisan::call('dply:cloud:env', [
            'site' => $site->name,
            '--set' => ['KEY=value'],
            '--no-redeploy' => true,
        ]);

        $this->assertSame(0, $exit);
        Queue::assertNotPushed(RedeployCloudSiteJob::class);
    }

    public function test_rejects_when_neither_file_nor_set_provided(): void
    {
        $site = $this->makeContainerSite('');

        $exit = Artisan::call('dply:cloud:env', ['site' => $site->name]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Pass --file or one or more --set', Artisan::output());
    }

    public function test_rejects_when_both_file_and_set_provided(): void
    {
        $site = $this->makeContainerSite('');

        $exit = Artisan::call('dply:cloud:env', [
            'site' => $site->name,
            '--file' => '/tmp/nonexistent',
            '--set' => ['KEY=v'],
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not both', Artisan::output());
    }

    public function test_rejects_unreadable_file(): void
    {
        $site = $this->makeContainerSite('');

        $exit = Artisan::call('dply:cloud:env', [
            'site' => $site->name,
            '--file' => '/path/that/definitely/does/not/exist',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('File not readable', Artisan::output());
    }

    public function test_rejects_non_cloud_site(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create(['user_id' => $user->id]);
        $vmSite = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'name' => 'PHP Site',
            'type' => SiteType::Php,
        ]);

        $exit = Artisan::call('dply:cloud:env', [
            'site' => $vmSite->name,
            '--set' => ['KEY=v'],
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not a cloud container site', Artisan::output());
    }

    public function test_missing_site(): void
    {
        $exit = Artisan::call('dply:cloud:env', [
            'site' => 'does-not-exist',
            '--set' => ['KEY=v'],
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', Artisan::output());
    }

    private function makeContainerSite(string $envContent): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'edge-app',
            'slug' => 'edge-app',
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'nginx:1',
            'container_port' => 80,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'container_backend_id' => 'fake-app-1',
            'env_file_content' => $envContent,
            'status' => Site::STATUS_CONTAINER_ACTIVE,
        ]);
    }
}
