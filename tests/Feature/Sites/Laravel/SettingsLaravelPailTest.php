<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\Laravel;

use App\Enums\SiteType;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SettingsLaravelPailTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeLaravelSite(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);
        session(['current_organization_id' => $org->id]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Php,
            'document_root' => '/home/dply/app/current/public',
            'meta' => ['vm_runtime' => ['detected' => ['framework' => 'laravel']]],
        ]);

        return [$user, $server, $site];
    }

    public function test_load_pail_tails_log_file_and_strips_public_suffix(): void
    {
        [$user, $server, $site] = $this->makeLaravelSite();

        $logSample = "[2026-05-03 12:00:00] production.INFO: Hello, world.\n[2026-05-03 12:00:01] production.WARNING: Cache miss.\n";

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->once()
            ->withArgs(function ($s, $name, string $bash) {
                $this->assertSame('laravel:pail-tail', $name);
                // document_root /home/dply/app/current/public should yield
                // log path /home/dply/app/current/storage/logs/laravel.log
                $this->assertStringContainsString("'/home/dply/app/current/storage/logs/laravel.log'", $bash);
                $this->assertStringContainsString('tail -n 200', $bash);

                return true;
            })
            ->andReturn(new ProcessOutput($logSample, 0, false));

        $component = Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
            ->set('laravel_tab', 'pail')
            ->call('loadLaravelPail', $executor);

        $this->assertTrue($component->get('laravelPailLoaded'));
        $this->assertSame($logSample, $component->get('laravelPailBuffer'));
    }

    public function test_load_pail_handles_missing_log_file_gracefully(): void
    {
        [$user, $server, $site] = $this->makeLaravelSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->andReturn(new ProcessOutput('(no log file at /home/dply/app/current/storage/logs/laravel.log)', 0, false));

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
            ->set('laravel_tab', 'pail')
            ->call('loadLaravelPail', $executor)
            ->assertSet('laravelPailLoaded', true);
    }

    public function test_load_pail_records_inline_error_on_ssh_failure(): void
    {
        [$user, $server, $site] = $this->makeLaravelSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->andThrow(new \RuntimeException('ssh: connection refused'));

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
            ->set('laravel_tab', 'pail')
            ->call('loadLaravelPail', $executor)
            ->assertHasErrors('laravel_pail')
            ->assertSet('laravelPailLoaded', false);
    }
}
