<?php

declare(strict_types=1);

namespace Tests\Feature\SyncEnvFromServerJobTest;
use \App\Services\Sites\SiteEnvReader;
use App\Jobs\SyncEnvFromServerJob;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('happy path writes cache and marks origin server', function () {
    $server = Server::factory()->ready()->create(['ssh_private_key' => 'fake']);
    $site = Site::factory()->create(['server_id' => $server->id]);

    bindFakeReader("APP_NAME=hello\nAPP_DEBUG=true\n");

    (new SyncEnvFromServerJob($site->id))->handle(
        app(SiteEnvReader::class),
        app(DotEnvFileParser::class),
    );

    $site->refresh();
    expect($site->env_file_content)->toBe("APP_NAME=hello\nAPP_DEBUG=true\n");
    expect($site->env_cache_origin)->toBe('server');
    expect($site->env_synced_at)->not->toBeNull();

    // Console action row exists, completed, with the right kind.
    $row = ConsoleAction::query()
        ->where('subject_type', $site->getMorphClass())
        ->where('subject_id', $site->id)
        ->where('kind', 'env_sync')
        ->latest()
        ->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe(ConsoleAction::STATUS_COMPLETED);
});
test('parser warnings are emitted but job still succeeds', function () {
    $server = Server::factory()->ready()->create(['ssh_private_key' => 'fake']);
    $site = Site::factory()->create(['server_id' => $server->id]);

    // Server has a malformed line — we still want to capture what the
    // server actually has, so the job emits warnings and persists.
    bindFakeReader("GOOD=ok\nMALFORMED_LINE\n");

    (new SyncEnvFromServerJob($site->id))->handle(
        app(SiteEnvReader::class),
        app(DotEnvFileParser::class),
    );

    $site->refresh();
    expect($site->env_file_content)->toBe("GOOD=ok\nMALFORMED_LINE\n");

    $row = ConsoleAction::query()
        ->where('subject_id', $site->id)
        ->where('kind', 'env_sync')
        ->latest()
        ->first();
    expect($row->status)->toBe(ConsoleAction::STATUS_COMPLETED);
    $lines = collect($row->output['lines'] ?? [])->pluck('level')->all();
    expect($lines)->toContain('warn');
});
test('reader failure marks run failed', function () {
    $server = Server::factory()->ready()->create(['ssh_private_key' => 'fake']);
    $site = Site::factory()->create(['server_id' => $server->id]);

    $this->app->bind(SiteEnvReader::class, fn () => new class extends SiteEnvReader
    {
        function __construct()
        {
        }

        function read(Site $site): string
        {
            throw new \RuntimeException('connection refused');
        }
    });

    $this->expectException(\RuntimeException::class);

    try {
        (new SyncEnvFromServerJob($site->id))->handle(
            app(SiteEnvReader::class),
            app(DotEnvFileParser::class),
        );
    } finally {
        $row = ConsoleAction::query()
            ->where('subject_id', $site->id)
            ->where('kind', 'env_sync')
            ->latest()
            ->first();
        expect($row->status)->toBe(ConsoleAction::STATUS_FAILED);
    }
});
function bindFakeReader(string $payload): void
{
    app()->bind(SiteEnvReader::class, fn () => new class($payload) extends SiteEnvReader
    {
        public function __construct(private readonly string $payload)
        {
            // Bypass parent constructor — no SSH wrapper needed for the fake.
        }

        public function read(Site $site): string
        {
            return $this->payload;
        }
    });
}
