<?php

namespace Tests\Unit\Services\SshKeyLabelTemplateTest;

use App\Models\Organization;
use App\Models\Server;
use App\Services\Servers\SshKeyLabelTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('server meta overrides organization preference', function () {
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

    expect(SshKeyLabelTemplate::resolveTemplate($server))->toBe('{name}-srv');
});

test('organization preference used when server meta empty', function () {
    $org = Organization::factory()->create([
        'server_site_preferences' => [
            'ssh_key_label_template' => '{hostname} · {name}',
        ],
    ]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'meta' => [],
    ]);

    expect(SshKeyLabelTemplate::resolveTemplate($server))->toBe('{hostname} · {name}');
});

test('default is literal name placeholder', function () {
    $server = Server::factory()->create(['meta' => []]);

    expect(SshKeyLabelTemplate::resolveTemplate($server))->toBe('{name}');
});

test('apply replaces placeholders', function () {
    $server = Server::factory()->create(['name' => 'app-1']);

    $out = SshKeyLabelTemplate::apply('{user}@{hostname} {date}', 'MyKey', 'deploy', $server);

    $this->assertStringContainsString('deploy', $out);
    $this->assertStringContainsString('app-1', $out);
    $this->assertStringContainsString((string) now()->toDateString(), $out);
});
