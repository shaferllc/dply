<?php

namespace Tests\Unit\Services\ConfigRevisions;

use App\Models\ConfigRevision;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\ConfigRevisions\ConfigRevisionContext;
use App\Services\ConfigRevisions\ConfigRevisionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConfigRevisionRecorderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_revision_with_denormalized_owner_pointers(): void
    {
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

        $this->assertNotNull($rev);
        $this->assertSame($streamKey, $rev->stream_key);
        $this->assertSame('php_cli_ini', $rev->kind);
        $this->assertSame($server->id, $rev->server_id);
        $this->assertSame($user->id, $rev->user_id);
        $this->assertSame('bumped memory', $rev->summary);
        $this->assertSame("memory_limit=512M\n", $rev->snapshot['content']);
        $this->assertSame(64, strlen($rev->checksum));
    }

    #[Test]
    public function it_dedupes_identical_back_to_back_captures(): void
    {
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

        $this->assertNotNull($first);
        $this->assertNull($second, 'identical content should be deduped against the most recent revision');
        $this->assertSame(1, ConfigRevision::query()->where('stream_key', $streamKey)->count());
    }

    #[Test]
    public function key_order_in_snapshot_does_not_change_the_checksum(): void
    {
        $recorder = app(ConfigRevisionRecorder::class);

        $a = $recorder->checksumFor(['path' => '/foo', 'content' => 'x']);
        $b = $recorder->checksumFor(['content' => 'x', 'path' => '/foo']);

        $this->assertSame($a, $b);
    }

    #[Test]
    public function it_records_subject_polymorphic_pointer_when_provided(): void
    {
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

        $this->assertNotNull($rev);
        $this->assertSame(Site::class, $rev->subject_type);
        $this->assertSame($site->id, $rev->subject_id);
    }
}
