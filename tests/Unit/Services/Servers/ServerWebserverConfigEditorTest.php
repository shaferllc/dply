<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Models\ConfigRevision;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Modules\ConfigRevisions\Services\Diff\ConfigRevisionDiffRegistry;
use App\Services\Servers\ServerWebserverConfigEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('webserver config editor records deduped revisions per file stream', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $editor = app(ServerWebserverConfigEditor::class);
    $path = '/etc/nginx/nginx.conf';

    $editor->ensureBaseline($server, 'nginx', $path, "worker_processes 1;\n", $user);
    $editor->recordWrite($server, 'nginx', $path, "worker_processes 2;\n", $user);
    $editor->recordWrite($server, 'nginx', $path, "worker_processes 2;\n", $user);

    expect(ConfigRevision::query()->count())->toBe(2);
});

test('webserver config diff renderer compares file contents', function (): void {
    $registry = app(ConfigRevisionDiffRegistry::class);
    $renderer = $registry->rendererFor(ServerWebserverConfigEditor::KIND);

    $diff = $renderer->render(
        ['path' => '/etc/nginx/nginx.conf', 'content' => "a\n"],
        ['path' => '/etc/nginx/nginx.conf', 'content' => "b\n"],
    );

    expect($diff)->toContain('-a')
        ->and($diff)->toContain('+b');
});
