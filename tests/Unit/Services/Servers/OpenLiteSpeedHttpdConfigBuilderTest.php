<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Services\Servers\OpenLiteSpeedHttpdConfigBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the dply-owned httpd_config.conf renderer. Switch flow writes
 * this once at provision (bound to :8080) and again at cutover (:80), so the
 * port-swap correctness and the per-site vhTemplate emission are the
 * load-bearing assertions here.
 */
class OpenLiteSpeedHttpdConfigBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithOrg(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $user->update(['current_organization_id' => $org->id]);

        return $user->fresh();
    }

    public function test_emits_listener_block_bound_to_provided_port(): void
    {
        $sites = new Collection([]);

        $out = app(OpenLiteSpeedHttpdConfigBuilder::class)->build($sites, 8080);

        $this->assertStringContainsString('listener Default {', $out);
        $this->assertMatchesRegularExpression('/address\s+\*:8080\b/', $out);
        $this->assertDoesNotMatchRegularExpression('/address\s+\*:80\b/', $out);
    }

    public function test_cutover_listener_bound_to_80(): void
    {
        $sites = new Collection([]);

        $out = app(OpenLiteSpeedHttpdConfigBuilder::class)->build($sites, 80);

        $this->assertMatchesRegularExpression('/address\s+\*:80\b/', $out);
        $this->assertDoesNotMatchRegularExpression('/address\s+\*:8080\b/', $out);
    }

    public function test_emits_vhtemplate_block_per_site(): void
    {
        $user = $this->makeUserWithOrg();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
        ]);

        $site1 = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'name' => 'first-site',
            'runtime' => 'php',
        ]);
        $site2 = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'name' => 'second-site',
            'runtime' => 'static',
        ]);

        SiteDomain::query()->create([
            'site_id' => $site1->id,
            'hostname' => 'first.example.com',
            'is_primary' => true,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site2->id,
            'hostname' => 'second.example.com',
            'is_primary' => true,
        ]);

        $sites = new Collection([$site1->fresh(), $site2->fresh()]);
        $out = app(OpenLiteSpeedHttpdConfigBuilder::class)->build($sites, 8080);

        $basename1 = $site1->webserverConfigBasename();
        $basename2 = $site2->webserverConfigBasename();

        $this->assertStringContainsString("vhTemplate {$basename1} {", $out);
        $this->assertStringContainsString("vhTemplate {$basename2} {", $out);
        $this->assertStringContainsString('templateFile            /usr/local/lsws/conf/vhosts/'.$basename1.'/vhconf.conf', $out);
        $this->assertStringContainsString('templateFile            /usr/local/lsws/conf/vhosts/'.$basename2.'/vhconf.conf', $out);
        $this->assertStringContainsString('vhDomain                first.example.com', $out);
        $this->assertStringContainsString('vhDomain                second.example.com', $out);
        // vhRoot pinned to repo path so $VH_ROOT in vhconf resolves to a
        // directory dply manages, not the default conf/vhosts/<name>.
        $this->assertStringContainsString('vhRoot                  '.rtrim($site1->effectiveRepositoryPath(), '/'), $out);
    }

    public function test_marks_output_as_dply_managed(): void
    {
        $out = app(OpenLiteSpeedHttpdConfigBuilder::class)->build(new Collection([]), 80);

        $this->assertStringContainsString('Managed by Dply', $out);
        $this->assertStringContainsString('do NOT hand-edit', $out);
    }
}
