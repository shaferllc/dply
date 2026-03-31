<?php

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Services\Servers\SshKeyLabelTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SshKeyLabelTemplateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function server_meta_overrides_organization_preference(): void
    {
        $org = Organization::factory()->create([
            'server_site_preferences' => [
                'ssh_key_label_template' => '{name}-org',
            ],
        ]);

        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'meta' => [
                'ssh_key_label_template' => '{name}-srv',
            ],
        ]);

        $this->assertSame('{name}-srv', SshKeyLabelTemplate::resolveTemplate($server));
    }

    #[Test]
    public function organization_preference_used_when_server_meta_empty(): void
    {
        $org = Organization::factory()->create([
            'server_site_preferences' => [
                'ssh_key_label_template' => '{hostname} · {name}',
            ],
        ]);

        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'meta' => [],
        ]);

        $this->assertSame('{hostname} · {name}', SshKeyLabelTemplate::resolveTemplate($server));
    }

    #[Test]
    public function default_is_literal_name_placeholder(): void
    {
        $server = Server::factory()->create(['meta' => []]);

        $this->assertSame('{name}', SshKeyLabelTemplate::resolveTemplate($server));
    }

    #[Test]
    public function apply_replaces_placeholders(): void
    {
        $server = Server::factory()->create(['name' => 'app-1']);

        $out = SshKeyLabelTemplate::apply('{user}@{hostname} {date}', 'MyKey', 'deploy', $server);

        $this->assertStringContainsString('deploy', $out);
        $this->assertStringContainsString('app-1', $out);
        $this->assertStringContainsString((string) now()->toDateString(), $out);
    }
}
