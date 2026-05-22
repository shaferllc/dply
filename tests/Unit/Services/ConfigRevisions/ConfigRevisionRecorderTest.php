<?php


namespace Tests\Unit\Services\ConfigRevisions\ConfigRevisionRecorderTest;
use App\Models\ConfigRevision;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\ConfigRevisions\ConfigRevisionContext;
use App\Services\ConfigRevisions\ConfigRevisionRecorder;
use PHPUnit\Framework\Attributes\Test;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('creates a revision with denormalized owner pointers', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id]);

    $recorder = app(ConfigRevisionRecorder::class);
    $streamKey = 'server:'.$server->id.':php:8.4:cli_ini';

    $rev = $recorder->capture(
        $streamKey,
        'php_cli_ini',
        ['path' => '/etc/php/8.4/cli/php.ini', 'content' => "memory_limit=512M\n"],
        new ConfigRevisionContext(server: $server, user: $user, summary: 'bumped memory'),
    );

    expect($rev)->not->toBeNull();
    expect($rev->stream_key)->toBe($streamKey);
    expect($rev->kind)->toBe('php_cli_ini');
    expect($rev->server_id)->toBe($server->id);
    expect($rev->user_id)->toBe($user->id);
    expect($rev->summary)->toBe('bumped memory');
    expect($rev->snapshot['content'])->toBe("memory_limit=512M\n");
    expect(strlen($rev->checksum))->toBe(64);
});

it('dedupes identical back to back captures', function () {
    $server = Server::factory()->create();
    $recorder = app(ConfigRevisionRecorder::class);
    $streamKey = 'server:'.$server->id.':php:8.4:cli_ini';

    $first = $recorder->capture(
        $streamKey,
        'php_cli_ini',
        ['path' => '/etc/php/8.4/cli/php.ini', 'content' => "a=1\n"],
        new ConfigRevisionContext(server: $server),
    );
    $second = $recorder->capture(
        $streamKey,
        'php_cli_ini',
        ['path' => '/etc/php/8.4/cli/php.ini', 'content' => "a=1\n"],
        new ConfigRevisionContext(server: $server),
    );

    expect($first)->not->toBeNull();
    expect($second)->toBeNull('identical content should be deduped against the most recent revision');
    expect(ConfigRevision::query()->where('stream_key', $streamKey)->count())->toBe(1);
});

test('key order in snapshot does not change the checksum', function () {
    $recorder = app(ConfigRevisionRecorder::class);

    $a = $recorder->checksumFor(['path' => '/foo', 'content' => 'x']);
    $b = $recorder->checksumFor(['content' => 'x', 'path' => '/foo']);

    expect($b)->toBe($a);
});

it('records subject polymorphic pointer when provided', function () {
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id]);

    $recorder = app(ConfigRevisionRecorder::class);
    $rev = $recorder->capture(
        'site:'.$site->id.':webserver_config',
        'webserver_config',
        ['mode' => 'layered', 'main_snippet_body' => 'hi'],
        new ConfigRevisionContext(server: $server, subject: $site),
    );

    expect($rev)->not->toBeNull();
    expect($rev->subject_type)->toBe(Site::class);
    expect($rev->subject_id)->toBe($site->id);
});