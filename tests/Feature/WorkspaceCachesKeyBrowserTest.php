<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Servers\WorkspaceCaches;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Support\Servers\CacheServiceCli;
use App\Support\Servers\CacheServiceKeyExplorer;
use App\Support\Servers\CacheServiceStats;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceCachesKeyBrowserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->mock(CacheServiceStats::class, function ($mock): void {
            $mock->shouldReceive('snapshot')->byDefault()->andReturn([]);
            $mock->shouldReceive('forget')->byDefault()->andReturnNull();
        });

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->byDefault()->andReturn([
                'redis' => true, 'valkey' => false, 'memcached' => false,
                'keydb' => false, 'dragonfly' => false,
            ]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });
    }

    /**
     * @return array{User, Server}
     */
    protected function actingOwnerWithRedis(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ssh_user' => 'root',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1lZDI1NTE5AAAA\n-----END OPENSSH PRIVATE KEY-----",
        ]);

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        return [$user, $server];
    }

    public function test_search_loads_first_page_of_keys(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        $this->mock(CacheServiceKeyExplorer::class, function ($mock): void {
            $mock->shouldReceive('scan')
                ->once()
                ->withArgs(fn ($srv, $row, $cursor, $pattern) => $cursor === '0' && $pattern === '*')
                ->andReturn([
                    'cursor' => '12',
                    'keys' => ['session:abc', 'session:def', 'cache:home'],
                    'complete' => false,
                ]);
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->call('searchKeyBrowser')
            ->assertHasNoErrors()
            ->assertSet('keyBrowserLoaded', true)
            ->assertSet('keyBrowserCursor', '12')
            ->assertSet('keyBrowserComplete', false)
            ->tap(function ($component): void {
                $this->assertSame(['session:abc', 'session:def', 'cache:home'], $component->get('keyBrowserKeys'));
            });
    }

    public function test_load_more_appends_next_page_and_dedupes(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        $this->mock(CacheServiceKeyExplorer::class, function ($mock): void {
            $mock->shouldReceive('scan')
                ->andReturn(
                    ['cursor' => '12', 'keys' => ['a', 'b'], 'complete' => false],
                    ['cursor' => '0', 'keys' => ['b', 'c'], 'complete' => true],
                );
        });

        $component = Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->call('searchKeyBrowser');

        $component->call('loadKeyBrowserPage')
            ->assertSet('keyBrowserComplete', true);

        // Dedup: 'b' appears in both pages but should only appear once.
        $this->assertSame(['a', 'b', 'c'], $component->get('keyBrowserKeys'));
    }

    public function test_load_more_is_a_noop_after_complete(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        $this->mock(CacheServiceKeyExplorer::class, function ($mock): void {
            // Only the initial search should call scan; load_more after completion is a no-op.
            $mock->shouldReceive('scan')
                ->once()
                ->andReturn(['cursor' => '0', 'keys' => ['x'], 'complete' => true]);
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->call('searchKeyBrowser')
            ->assertSet('keyBrowserComplete', true)
            ->call('loadKeyBrowserPage')
            ->assertHasNoErrors();
    }

    public function test_inspect_key_populates_value(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        $this->mock(CacheServiceKeyExplorer::class, function ($mock): void {
            $mock->shouldReceive('scan')->andReturn(['cursor' => '0', 'keys' => ['session:abc'], 'complete' => true]);
            $mock->shouldReceive('inspect')
                ->once()
                ->withArgs(fn ($srv, $row, $key) => $key === 'session:abc')
                ->andReturn([
                    'type' => 'string',
                    'ttl' => 3600,
                    'value' => 'eyJ1c2VyX2lkIjogNDJ9',
                    'truncated' => false,
                ]);
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->call('searchKeyBrowser')
            ->call('inspectKey', 'session:abc')
            ->assertSet('keyBrowserSelected', 'session:abc')
            ->assertSet('keyBrowserValue.type', 'string')
            ->assertSet('keyBrowserValue.ttl', 3600);
    }

    public function test_inspect_key_surfaces_error_on_failure(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        $this->mock(CacheServiceKeyExplorer::class, function ($mock): void {
            $mock->shouldReceive('inspect')->andThrow(new \RuntimeException('boom'));
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->call('inspectKey', 'somekey')
            ->assertSet('keyBrowserSelected', 'somekey')
            ->assertSet('keyBrowserValue', null)
            ->assertSet('keyBrowserValueError', 'boom');
    }

    public function test_delete_key_requires_unlock_toggle(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        $this->mock(CacheServiceCli::class, function ($mock): void {
            $mock->shouldNotReceive('execute');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('keyBrowserKeys', ['target'])
            ->call('deleteKey', 'target')
            ->assertHasNoErrors();

        // No DEL audit row.
        $this->assertSame(0, ServerCacheServiceAuditEvent::query()
            ->where('server_id', $server->id)
            ->where('event', ServerCacheServiceAuditEvent::EVENT_REPL_EXECUTED)
            ->count());
    }

    public function test_delete_key_runs_when_unlocked_and_audits(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        $this->mock(CacheServiceCli::class, function ($mock): void {
            $mock->shouldReceive('execute')
                ->once()
                ->withArgs(fn ($srv, $row, $cmd) => $cmd === 'DEL target')
                ->andReturn(new ProcessOutput("(integer) 1\n", 0, false));
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('keyBrowserKeys', ['target', 'keep'])
            ->set('replUnlocked', true)
            ->call('deleteKey', 'target')
            ->assertHasNoErrors()
            ->tap(function ($component): void {
                $this->assertSame(['keep'], $component->get('keyBrowserKeys'));
            });

        $event = ServerCacheServiceAuditEvent::query()
            ->where('server_id', $server->id)
            ->where('event', ServerCacheServiceAuditEvent::EVENT_REPL_EXECUTED)
            ->latest()
            ->firstOrFail();
        $this->assertSame('DEL', $event->meta['verb']);
        $this->assertTrue((bool) $event->meta['mutating']);
    }

    public function test_key_browser_rejects_memcached(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ssh_user' => 'root',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1lZDI1NTE5AAAA\n-----END OPENSSH PRIVATE KEY-----",
        ]);
        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'memcached',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 11211,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn([
                'redis' => false, 'valkey' => false, 'memcached' => true,
                'keydb' => false, 'dragonfly' => false,
            ]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(CacheServiceKeyExplorer::class, function ($mock): void {
            $mock->shouldNotReceive('scan');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'memcached')
            ->call('searchKeyBrowser')
            ->tap(function ($component): void {
                $this->assertNotNull($component->get('keyBrowserError'));
            });
    }
}
