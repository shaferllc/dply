<?php


namespace Tests\Unit\Services\ServerPhpConfigEditorRevisionsTest;
use Mockery;

use App\Models\ConfigRevision;
use \Mockery\MockInterface;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\ConfigRevisions\ConfigRevisionRecorder;
use App\Services\Servers\ServerPhpConfigEditor;
use PHPUnit\Framework\Attributes\Test;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeServerWithMeta(array $meta = []): Server
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

function editorWithMockedRemoteOps(Server $server, ?string $preContent = null): ServerPhpConfigEditor&Mockery\MockInterface
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

test('first save captures a baseline from disk then the post save content', function () {
    $server = makeServerWithMeta();
    $user = User::factory()->create();
    $editor = editorWithMockedRemoteOps($server, preContent: "memory_limit=128M\n");

    $editor->saveTarget($server, '8.3', 'cli_ini', "memory_limit=512M\n", $user);

    $streamKey = $editor->streamKey($server, '8.3', 'cli_ini');
    $revisions = ConfigRevision::query()->where('stream_key', $streamKey)->orderBy('created_at')->orderBy('id')->get();

    expect($revisions)->toHaveCount(2, 'first save should produce baseline + post-save revisions');
    expect($revisions[0]->snapshot['content'])->toBe("memory_limit=128M\n");
    expect($revisions[1]->snapshot['content'])->toBe("memory_limit=512M\n");
    expect($revisions[1]->kind)->toBe('php_cli_ini');
    expect($revisions[1]->user_id)->toBe($user->id);
});

test('subsequent saves do not recapture the baseline', function () {
    $server = makeServerWithMeta();

    // Seed a prior revision so the baseline branch is skipped.
    $existing = ConfigRevision::query()->create([
        'stream_key' => 'server:'.$server->id.':php:8.3:cli_ini',
        'server_id' => $server->id,
        'kind' => 'php_cli_ini',
        'snapshot' => ['path' => '/etc/php/8.3/cli/php.ini', 'content' => "memory_limit=256M\n"],
        'checksum' => hash('sha256', json_encode(['content' => "memory_limit=256M\n", 'path' => '/etc/php/8.3/cli/php.ini'])),
    ]);

    $editor = editorWithMockedRemoteOps($server);
    $editor->shouldNotReceive('readRemoteTarget');

    $editor->saveTarget($server, '8.3', 'cli_ini', "memory_limit=512M\n");

    $count = ConfigRevision::query()
        ->where('stream_key', 'server:'.$server->id.':php:8.3:cli_ini')
        ->count();
    expect($count)->toBe(2, 'should add only the post-save revision');
    expect(ConfigRevision::find($existing->id))->not->toBeNull();
});

test('saving identical content is deduped and writes no new revision', function () {
    $server = makeServerWithMeta();
    $editor = editorWithMockedRemoteOps($server, preContent: "memory_limit=512M\n");

    $editor->saveTarget($server, '8.3', 'cli_ini', "memory_limit=512M\n");

    $count = ConfigRevision::query()
        ->where('stream_key', 'server:'.$server->id.':php:8.3:cli_ini')
        ->count();

    // baseline is captured (= pre content), but the post-save snapshot is
    // byte-identical and deduped, so we end with exactly one revision.
    expect($count)->toBe(1);
});

test('capture live as revision reads remote and writes a revision', function () {
    $server = makeServerWithMeta();
    $user = User::factory()->create();
    $editor = Mockery::mock(
        ServerPhpConfigEditor::class,
        [app(ConfigRevisionRecorder::class)]
    )->makePartial()->shouldAllowMockingProtectedMethods();
    $editor->shouldReceive('readRemoteTarget')
        ->once()
        ->andReturn("memory_limit=999M\n");

    $rev = $editor->captureLiveAsRevision($server, '8.3', 'cli_ini', $user, 'drift snapshot');

    expect($rev)->not->toBeNull();
    expect($rev->kind)->toBe('php_cli_ini');
    expect($rev->snapshot['content'])->toBe("memory_limit=999M\n");
    expect($rev->summary)->toBe('drift snapshot');
    expect($rev->user_id)->toBe($user->id);
});

test('stream key and kind helpers are stable and disambiguate targets', function () {
    $server = makeServerWithMeta();
    $editor = app(ServerPhpConfigEditor::class);

    expect($editor->streamKey($server, '8.3', 'cli_ini'))->toBe('server:'.$server->id.':php:8.3:cli_ini');
    expect($editor->kindForTarget('cli_ini'))->toBe('php_cli_ini');
    expect($editor->kindForTarget('fpm_ini'))->toBe('php_fpm_ini');
    expect($editor->kindForTarget('pool_config'))->toBe('php_pool');
});