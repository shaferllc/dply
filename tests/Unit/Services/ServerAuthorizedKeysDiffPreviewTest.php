<?php

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\User;
use App\Services\Servers\ServerAuthorizedKeysDiffPreview;
use App\Services\Servers\ServerAuthorizedKeysRemoteReader;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServerAuthorizedKeysDiffPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function validPrivateKey(): string
    {
        return file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem'));
    }

    #[Test]
    public function it_reports_added_and_removed_lines_per_user(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ssh_user' => 'root',
            'ssh_private_key' => $this->validPrivateKey(),
            'meta' => [
                ServerAuthorizedKeysSynchronizer::META_SYNCED_LINUX_USERS_KEY => ['root'],
            ],
        ]);

        $kPanel = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHBhbmVsLWtleS1saW5lLXBsYWNlaG9sZGVy';
        ServerAuthorizedKey::query()->create([
            'server_id' => $server->id,
            'name' => 'panel',
            'public_key' => $kPanel,
            'target_linux_user' => '',
        ]);

        $kRemoteOld = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHJlbW90ZS1vbGQta2V5LWxpbmU';

        $reader = Mockery::mock(ServerAuthorizedKeysRemoteReader::class);
        $reader->shouldReceive('normalizedKeyLines')
            ->once()
            ->with(Mockery::on(fn (Server $s) => $s->is($server)), 'root')
            ->andReturn([$kRemoteOld]);

        $preview = new ServerAuthorizedKeysDiffPreview($reader);
        $diff = $preview->diffPerUser($server->fresh(['authorizedKeys']));

        $this->assertArrayHasKey('root', $diff);
        $this->assertSame([$kPanel], $diff['root']['desired']);
        $this->assertSame([$kRemoteOld], $diff['root']['remote']);
        $this->assertSame([$kPanel], $diff['root']['added']);
        $this->assertSame([$kRemoteOld], $diff['root']['removed']);
    }
}
