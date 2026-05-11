<?php

namespace Tests\Unit\Services;

use App\Models\ConfigRevision;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\ConfigRevisions\ConfigRevisionRecorder;
use App\Services\Servers\ServerPhpConfigEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServerPhpConfigEditorRevisionsTest extends TestCase
{
    use RefreshDatabase;

    protected function makeServerWithMeta(array $meta = []): Server
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $user->organizations()->attach($org->id, ['role' => 'owner']);

        return Server::factory()->create([
            'organization_id' => $org->id,
            'meta' => array_merge([
                'server_role' => 'application',
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.3'],
                    'detected_default_version' => '8.3',
                ],
            ], $meta),
            'ip_address' => '203.0.113.10',
            'ssh_user' => 'root',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'status' => Server::STATUS_READY,
            'setup_status' => Server::SETUP_STATUS_DONE,
        ]);
    }

    protected function editorWithMockedRemoteOps(Server $server, ?string $preContent = null): ServerPhpConfigEditor&Mockery\MockInterface
    {
        $editor = Mockery::mock(
            ServerPhpConfigEditor::class,
            [app(ConfigRevisionRecorder::class)]
        )->makePartial()->shouldAllowMockingProtectedMethods();

        if ($preContent !== null) {
            $editor->shouldReceive('readRemoteTarget')
                ->andReturn($preContent);
        }

        $editor->shouldReceive('verifyProposedContent')
            ->andReturn(['output' => 'ok']);
        $editor->shouldReceive('replaceRemoteTarget')->andReturn(null);
        $editor->shouldReceive('reloadRuntimeIfNeeded')->andReturn(null);

        return $editor;
    }

    #[Test]
    public function first_save_captures_a_baseline_from_disk_then_the_post_save_content(): void
    {
        $server = $this->makeServerWithMeta();
        $user = User::factory()->create();
        $editor = $this->editorWithMockedRemoteOps($server, preContent: "memory_limit=128M\n");

        $editor->saveTarget($server, '8.3', 'cli_ini', "memory_limit=512M\n", $user);

        $streamKey = $editor->streamKey($server, '8.3', 'cli_ini');
        $revisions = ConfigRevision::query()->where('stream_key', $streamKey)->orderBy('created_at')->orderBy('id')->get();

        $this->assertCount(2, $revisions, 'first save should produce baseline + post-save revisions');
        $this->assertSame("memory_limit=128M\n", $revisions[0]->snapshot['content']);
        $this->assertSame("memory_limit=512M\n", $revisions[1]->snapshot['content']);
        $this->assertSame('php_cli_ini', $revisions[1]->kind);
        $this->assertSame($user->id, $revisions[1]->user_id);
    }

    #[Test]
    public function subsequent_saves_do_not_recapture_the_baseline(): void
    {
        $server = $this->makeServerWithMeta();

        // Seed a prior revision so the baseline branch is skipped.
        $existing = ConfigRevision::query()->create([
            'stream_key' => 'server:'.$server->id.':php:8.3:cli_ini',
            'server_id' => $server->id,
            'kind' => 'php_cli_ini',
            'snapshot' => ['path' => '/etc/php/8.3/cli/php.ini', 'content' => "memory_limit=256M\n"],
            'checksum' => hash('sha256', json_encode(['content' => "memory_limit=256M\n", 'path' => '/etc/php/8.3/cli/php.ini'])),
        ]);

        $editor = $this->editorWithMockedRemoteOps($server);
        $editor->shouldNotReceive('readRemoteTarget');

        $editor->saveTarget($server, '8.3', 'cli_ini', "memory_limit=512M\n");

        $count = ConfigRevision::query()
            ->where('stream_key', 'server:'.$server->id.':php:8.3:cli_ini')
            ->count();
        $this->assertSame(2, $count, 'should add only the post-save revision');
        $this->assertNotNull(ConfigRevision::find($existing->id));
    }

    #[Test]
    public function saving_identical_content_is_deduped_and_writes_no_new_revision(): void
    {
        $server = $this->makeServerWithMeta();
        $editor = $this->editorWithMockedRemoteOps($server, preContent: "memory_limit=512M\n");

        $editor->saveTarget($server, '8.3', 'cli_ini', "memory_limit=512M\n");

        $count = ConfigRevision::query()
            ->where('stream_key', 'server:'.$server->id.':php:8.3:cli_ini')
            ->count();
        // baseline is captured (= pre content), but the post-save snapshot is
        // byte-identical and deduped, so we end with exactly one revision.
        $this->assertSame(1, $count);
    }

    #[Test]
    public function capture_live_as_revision_reads_remote_and_writes_a_revision(): void
    {
        $server = $this->makeServerWithMeta();
        $user = User::factory()->create();
        $editor = Mockery::mock(
            ServerPhpConfigEditor::class,
            [app(ConfigRevisionRecorder::class)]
        )->makePartial()->shouldAllowMockingProtectedMethods();
        $editor->shouldReceive('readRemoteTarget')
            ->once()
            ->andReturn("memory_limit=999M\n");

        $rev = $editor->captureLiveAsRevision($server, '8.3', 'cli_ini', $user, 'drift snapshot');

        $this->assertNotNull($rev);
        $this->assertSame('php_cli_ini', $rev->kind);
        $this->assertSame("memory_limit=999M\n", $rev->snapshot['content']);
        $this->assertSame('drift snapshot', $rev->summary);
        $this->assertSame($user->id, $rev->user_id);
    }

    #[Test]
    public function stream_key_and_kind_helpers_are_stable_and_disambiguate_targets(): void
    {
        $server = $this->makeServerWithMeta();
        $editor = app(ServerPhpConfigEditor::class);

        $this->assertSame(
            'server:'.$server->id.':php:8.3:cli_ini',
            $editor->streamKey($server, '8.3', 'cli_ini'),
        );
        $this->assertSame('php_cli_ini', $editor->kindForTarget('cli_ini'));
        $this->assertSame('php_fpm_ini', $editor->kindForTarget('fpm_ini'));
        $this->assertSame('php_pool', $editor->kindForTarget('pool_config'));
    }
}
