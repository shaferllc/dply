<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\Servers\ServerSystemLogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerSystemLogReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_returns_unknown_for_invalid_dynamic_site_key(): void
    {
        $server = Server::factory()->ready()->create();

        $result = app(ServerSystemLogReader::class)->fetch($server, 'site_not_a_ulid_access');

        $this->assertSame('', $result['output']);
        $this->assertSame(__('Unknown log source.'), $result['error']);
    }

    public function test_journal_sources_are_defined(): void
    {
        $sources = config('server_system_logs.sources', []);

        $this->assertArrayHasKey('journal_nginx', $sources);
        $this->assertSame('journal', $sources['journal_nginx']['type'] ?? null);
        $this->assertNotEmpty(config('server_system_logs.journal_allowed_units', []));
    }
}
