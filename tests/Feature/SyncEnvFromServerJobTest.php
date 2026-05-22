<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncEnvFromServerJob;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\SiteEnvReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncEnvFromServerJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_happy_path_writes_cache_and_marks_origin_server(): void
    {
        $server = Server::factory()->ready()->create(['ssh_private_key' => 'fake']);
        $site = Site::factory()->create(['server_id' => $server->id]);

        $this->bindFakeReader("APP_NAME=hello\nAPP_DEBUG=true\n");

        (new SyncEnvFromServerJob($site->id))->handle(
            app(SiteEnvReader::class),
            app(DotEnvFileParser::class),
        );

        $site->refresh();
        $this->assertSame("APP_NAME=hello\nAPP_DEBUG=true\n", $site->env_file_content);
        $this->assertSame('server', $site->env_cache_origin);
        $this->assertNotNull($site->env_synced_at);

        // Console action row exists, completed, with the right kind.
        $row = ConsoleAction::query()
            ->where('subject_type', $site->getMorphClass())
            ->where('subject_id', $site->id)
            ->where('kind', 'env_sync')
            ->latest()
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(ConsoleAction::STATUS_COMPLETED, $row->status);
    }

    public function test_parser_warnings_are_emitted_but_job_still_succeeds(): void
    {
        $server = Server::factory()->ready()->create(['ssh_private_key' => 'fake']);
        $site = Site::factory()->create(['server_id' => $server->id]);

        // Server has a malformed line — we still want to capture what the
        // server actually has, so the job emits warnings and persists.
        $this->bindFakeReader("GOOD=ok\nMALFORMED_LINE\n");

        (new SyncEnvFromServerJob($site->id))->handle(
            app(SiteEnvReader::class),
            app(DotEnvFileParser::class),
        );

        $site->refresh();
        $this->assertSame("GOOD=ok\nMALFORMED_LINE\n", $site->env_file_content);

        $row = ConsoleAction::query()
            ->where('subject_id', $site->id)
            ->where('kind', 'env_sync')
            ->latest()
            ->first();
        $this->assertSame(ConsoleAction::STATUS_COMPLETED, $row->status);
        $lines = collect($row->output['lines'] ?? [])->pluck('level')->all();
        $this->assertContains('warn', $lines);
    }

    public function test_reader_failure_marks_run_failed(): void
    {
        $server = Server::factory()->ready()->create(['ssh_private_key' => 'fake']);
        $site = Site::factory()->create(['server_id' => $server->id]);

        $this->app->bind(SiteEnvReader::class, fn () => new class extends SiteEnvReader
        {
            public function __construct() {}

            public function read(Site $site): string
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
            $this->assertSame(ConsoleAction::STATUS_FAILED, $row->status);
        }
    }

    private function bindFakeReader(string $payload): void
    {
        $this->app->bind(SiteEnvReader::class, fn () => new class($payload) extends SiteEnvReader
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
}
