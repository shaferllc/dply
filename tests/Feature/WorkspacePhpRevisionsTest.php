<?php

namespace Tests\Feature;

use App\Livewire\Servers\WorkspacePhp;
use App\Models\ConfigRevision;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\ConfigRevisions\ConfigRevisionRecorder;
use App\Services\Servers\ServerPhpConfigEditor;
use App\Services\Servers\ServerPhpManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class WorkspacePhpRevisionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function userWithOrganization(): User
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $user->organizations()->attach($org->id, ['role' => 'owner']);
        $user->update(['current_organization_id' => $org->id]);

        return $user->fresh();
    }

    protected function stubPhpManager(): void
    {
        $manager = Mockery::mock(ServerPhpManager::class);
        $manager->shouldReceive('workspaceData')->andReturn([
            'summary' => [
                'supported_versions' => [['id' => '8.3', 'label' => 'PHP 8.3']],
                'installed_versions' => [['id' => '8.3', 'label' => 'PHP 8.3', 'is_supported' => true, 'site_count' => 0]],
                'installed_count' => 1,
                'is_supported_environment' => true,
                'cli_default' => '8.3',
                'new_site_default' => '8.3',
                'detected_default_version' => '8.3',
            ],
            'version_rows' => [
                ['id' => '8.3', 'label' => 'PHP 8.3', 'is_supported' => true, 'is_installed' => true, 'site_count' => 0],
            ],
        ]);
        $this->app->instance(ServerPhpManager::class, $manager);
    }

    /** Build a server + a partial-mocked editor whose remote ops are stubbed. */
    protected function stubEditor(Server $server, string $liveContent, string $version = '8.3', string $target = 'cli_ini'): ServerPhpConfigEditor
    {
        $editor = Mockery::mock(
            ServerPhpConfigEditor::class,
            [app(ConfigRevisionRecorder::class)]
        )->makePartial()->shouldAllowMockingProtectedMethods();

        $editor->shouldReceive('openTarget')->andReturn([
            'version' => $version,
            'target' => $target,
            'label' => 'CLI ini',
            'path' => '/etc/php/'.$version.'/cli/php.ini',
            'content' => $liveContent,
            'reload_guidance' => 'noop',
        ]);
        $editor->shouldReceive('readRemoteTarget')->andReturn($liveContent);

        $this->app->instance(ServerPhpConfigEditor::class, $editor);

        return $editor;
    }

    public function test_opening_editor_with_no_prior_revisions_does_not_flag_drift(): void
    {
        $user = $this->userWithOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'meta' => ['php_inventory' => ['supported' => true, 'installed_versions' => ['8.3'], 'detected_default_version' => '8.3']],
        ]);

        $this->stubPhpManager();
        $this->stubEditor($server, "memory_limit=128M\n");

        Livewire::actingAs($user)
            ->test(WorkspacePhp::class, ['server' => $server])
            ->call('openPhpConfigEditor', '8.3', 'cli_ini')
            ->assertSet('phpConfigEditorDriftDetected', false)
            ->assertSet('phpConfigEditorCurrentRevisionId', null);
    }

    public function test_opening_editor_marks_latest_revision_as_current_when_live_matches(): void
    {
        $user = $this->userWithOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'meta' => ['php_inventory' => ['supported' => true, 'installed_versions' => ['8.3'], 'detected_default_version' => '8.3']],
        ]);

        $this->stubPhpManager();
        $editor = $this->stubEditor($server, "memory_limit=512M\n");

        // Pre-seed a revision whose snapshot matches the live content.
        $streamKey = $editor->streamKey($server, '8.3', 'cli_ini');
        $snapshot = ['path' => '/etc/php/8.3/cli/php.ini', 'content' => "memory_limit=512M\n"];
        $rev = ConfigRevision::query()->create([
            'stream_key' => $streamKey,
            'server_id' => $server->id,
            'kind' => 'php_cli_ini',
            'snapshot' => $snapshot,
            'checksum' => app(ConfigRevisionRecorder::class)->checksumFor($snapshot),
        ]);

        Livewire::actingAs($user)
            ->test(WorkspacePhp::class, ['server' => $server])
            ->call('openPhpConfigEditor', '8.3', 'cli_ini')
            ->assertSet('phpConfigEditorDriftDetected', false)
            ->assertSet('phpConfigEditorCurrentRevisionId', $rev->id);
    }

    public function test_opening_editor_flags_drift_when_live_differs_from_latest_revision(): void
    {
        $user = $this->userWithOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'meta' => ['php_inventory' => ['supported' => true, 'installed_versions' => ['8.3'], 'detected_default_version' => '8.3']],
        ]);

        $this->stubPhpManager();
        $editor = $this->stubEditor($server, "memory_limit=999M\n");

        $streamKey = $editor->streamKey($server, '8.3', 'cli_ini');
        $stale = ['path' => '/etc/php/8.3/cli/php.ini', 'content' => "memory_limit=128M\n"];
        ConfigRevision::query()->create([
            'stream_key' => $streamKey,
            'server_id' => $server->id,
            'kind' => 'php_cli_ini',
            'snapshot' => $stale,
            'checksum' => app(ConfigRevisionRecorder::class)->checksumFor($stale),
        ]);

        Livewire::actingAs($user)
            ->test(WorkspacePhp::class, ['server' => $server])
            ->call('openPhpConfigEditor', '8.3', 'cli_ini')
            ->assertSet('phpConfigEditorDriftDetected', true)
            ->assertSet('phpConfigEditorCurrentRevisionId', null);
    }

    public function test_load_revision_loads_snapshot_content_into_editor(): void
    {
        $user = $this->userWithOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'meta' => ['php_inventory' => ['supported' => true, 'installed_versions' => ['8.3'], 'detected_default_version' => '8.3']],
        ]);

        $this->stubPhpManager();
        $editor = $this->stubEditor($server, "memory_limit=512M\n");

        $streamKey = $editor->streamKey($server, '8.3', 'cli_ini');
        $rev = ConfigRevision::query()->create([
            'stream_key' => $streamKey,
            'server_id' => $server->id,
            'kind' => 'php_cli_ini',
            'snapshot' => ['path' => '/etc/php/8.3/cli/php.ini', 'content' => "memory_limit=64M\n"],
            'checksum' => 'irrelevant',
        ]);

        Livewire::actingAs($user)
            ->test(WorkspacePhp::class, ['server' => $server])
            ->call('openPhpConfigEditor', '8.3', 'cli_ini')
            ->call('loadRevision', $rev->id)
            ->assertSet('phpConfigEditorContent', "memory_limit=64M\n");
    }
}
