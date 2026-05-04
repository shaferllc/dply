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

    public function test_load_pail_initial_call_tails_lines_and_records_offset(): void
    {
        [$user, $server, $site] = $this->makeLaravelSite();

        $logSample = "[2026-05-03 12:00:00] production.INFO: Hello, world.\n[2026-05-03 12:00:01] production.WARNING: Cache miss.\n";
        $serverResponse = "DPLY-PAIL-SIZE:1024\n".$logSample;

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->once()
            ->withArgs(function ($s, $name, string $bash) {
                $this->assertSame('laravel:pail-tail', $name);
                // First fetch uses tail -n N (line count), not byte offset.
                $this->assertStringContainsString("'/home/dply/app/current/storage/logs/laravel.log'", $bash);
                $this->assertStringContainsString('tail -n 200', $bash);
                $this->assertStringContainsString('DPLY-PAIL-SIZE', $bash);

                return true;
            })
            ->andReturn(new ProcessOutput($serverResponse, 0, false));

        $component = Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
            ->set('laravel_tab', 'pail')
            ->call('loadLaravelPail', $executor);

        $this->assertTrue($component->get('laravelPailLoaded'));
        // Body comes through stripped of the size header.
        $this->assertSame($logSample, $component->get('laravelPailBuffer'));
        // Offset advances to the file's reported size so the next call
        // can stream tail -c +<offset+1>.
        $this->assertSame(1024, $component->get('laravelPailOffset'));
    }

    public function test_subsequent_load_pail_calls_use_byte_offset_and_append(): void
    {
        [$user, $server, $site] = $this->makeLaravelSite();

        $first = "DPLY-PAIL-SIZE:100\nfirst line\n";
        $second = "DPLY-PAIL-SIZE:200\nsecond line\n";

        $callIndex = 0;
        $bashCapture = [];
        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->andReturnUsing(function ($s, $name, $bash) use (&$callIndex, &$bashCapture, $first, $second) {
                $bashCapture[] = $bash;
                $callIndex++;

                return new ProcessOutput($callIndex === 1 ? $first : $second, 0, false);
            });

        $component = Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
            ->set('laravel_tab', 'pail')
            ->call('loadLaravelPail', $executor)
            ->call('loadLaravelPail', $executor);

        $this->assertCount(2, $bashCapture, 'loadLaravelPail should have been called twice');
        $this->assertStringContainsString('tail -n 200', $bashCapture[0], 'First call uses line-count tail to baseline');
        $this->assertStringContainsString('tail -c +101', $bashCapture[1], 'Second call uses byte-offset tail (offset 100 + 1)');
        $this->assertStringNotContainsString('tail -n', $bashCapture[1], 'Second call must NOT use line-count tail');

        // Buffer is the concatenation of both bodies (header stripped from each).
        $this->assertSame("first line\nsecond line\n", $component->get('laravelPailBuffer'));
        $this->assertSame(200, $component->get('laravelPailOffset'));
    }

    public function test_load_pail_handles_missing_log_file_gracefully(): void
    {
        [$user, $server, $site] = $this->makeLaravelSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->andReturn(new ProcessOutput('DPLY-PAIL-MISSING', 0, false));

        $component = Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
            ->set('laravel_tab', 'pail')
            ->call('loadLaravelPail', $executor)
            ->assertSet('laravelPailLoaded', true);

        $this->assertStringContainsString('no log file at', $component->get('laravelPailBuffer'));
    }

    public function test_toggle_live_flips_polling_flag(): void
    {
        [$user, $server, $site] = $this->makeLaravelSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
            ->set('laravel_tab', 'pail')
            ->assertSet('laravelPailLive', false)
            ->call('toggleLaravelPailLive')
            ->assertSet('laravelPailLive', true)
            ->call('toggleLaravelPailLive')
            ->assertSet('laravelPailLive', false);
    }

    public function test_reset_clears_buffer_and_rebaselines_offset(): void
    {
        [$user, $server, $site] = $this->makeLaravelSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
            ->set('laravel_tab', 'pail')
            ->set('laravelPailBuffer', 'something')
            ->set('laravelPailOffset', 9999)
            ->set('laravelPailLoaded', true)
            ->call('resetLaravelPail')
            ->assertSet('laravelPailBuffer', '')
            ->assertSet('laravelPailOffset', 0)
            ->assertSet('laravelPailLoaded', false);
    }

    public function test_buffer_truncates_when_above_cap(): void
    {
        [$user, $server, $site] = $this->makeLaravelSite();

        // Force initial state to "loaded with a near-cap buffer", then
        // append a chunk that pushes us over the limit.
        $existing = str_repeat('a', \App\Livewire\Sites\Settings::PAIL_BUFFER_MAX_CHARS - 100);
        $appendBody = str_repeat('b', 200);

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->andReturn(new ProcessOutput("DPLY-PAIL-SIZE:1\n".$appendBody, 0, false));

        $component = Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
            ->set('laravel_tab', 'pail')
            ->set('laravelPailLoaded', true)
            ->set('laravelPailOffset', 0)
            ->set('laravelPailBuffer', $existing)
            ->call('loadLaravelPail', $executor);

        $buffer = $component->get('laravelPailBuffer');
        $this->assertLessThanOrEqual(\App\Livewire\Sites\Settings::PAIL_BUFFER_MAX_CHARS + 100, strlen($buffer));
        $this->assertStringContainsString('older lines trimmed', $buffer);
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
