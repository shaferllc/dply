<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\Laravel\SettingsLaravelPailTest;
use Mockery;

use App\Enums\SiteType;
use App\Livewire\Sites\Settings;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});
function makeLaravelSite(): array
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
test('load pail initial call tails lines and records offset', function () {
    [$user, $server, $site] = makeLaravelSite();

    $logSample = "[2026-05-03 12:00:00] production.INFO: Hello, world.\n[2026-05-03 12:00:01] production.WARNING: Cache miss.\n";
    $serverResponse = "DPLY-PAIL-SIZE:1024\n".$logSample;

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(function ($s, $name, string $bash) {
            expect($name)->toBe('laravel:pail-tail');
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

    expect($component->get('laravelPailLoaded'))->toBeTrue();

    // Body comes through stripped of the size header.
    expect($component->get('laravelPailBuffer'))->toBe($logSample);

    // Offset advances to the file's reported size so the next call
    // can stream tail -c +<offset+1>.
    expect($component->get('laravelPailOffset'))->toBe(1024);
});
test('subsequent load pail calls use byte offset and append', function () {
    [$user, $server, $site] = makeLaravelSite();

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

    expect($bashCapture)->toHaveCount(2, 'loadLaravelPail should have been called twice');
    $this->assertStringContainsString('tail -n 200', $bashCapture[0], 'First call uses line-count tail to baseline');
    $this->assertStringContainsString('tail -c +101', $bashCapture[1], 'Second call uses byte-offset tail (offset 100 + 1)');
    $this->assertStringNotContainsString('tail -n', $bashCapture[1], 'Second call must NOT use line-count tail');

    // Buffer is the concatenation of both bodies (header stripped from each).
    expect($component->get('laravelPailBuffer'))->toBe("first line\nsecond line\n");
    expect($component->get('laravelPailOffset'))->toBe(200);
});
test('load pail handles missing log file gracefully', function () {
    [$user, $server, $site] = makeLaravelSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->andReturn(new ProcessOutput('DPLY-PAIL-MISSING', 0, false));

    $component = Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
        ->set('laravel_tab', 'pail')
        ->call('loadLaravelPail', $executor)
        ->assertSet('laravelPailLoaded', true);

    $this->assertStringContainsString('no log file at', $component->get('laravelPailBuffer'));
});
test('toggle live flips polling flag', function () {
    [$user, $server, $site] = makeLaravelSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
        ->set('laravel_tab', 'pail')
        ->assertSet('laravelPailLive', false)
        ->call('toggleLaravelPailLive')
        ->assertSet('laravelPailLive', true)
        ->call('toggleLaravelPailLive')
        ->assertSet('laravelPailLive', false);
});
test('reset clears buffer and rebaselines offset', function () {
    [$user, $server, $site] = makeLaravelSite();

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
});
test('buffer truncates when above cap', function () {
    [$user, $server, $site] = makeLaravelSite();

    // Force initial state to "loaded with a near-cap buffer", then
    // append a chunk that pushes us over the limit.
    $existing = str_repeat('a', Settings::PAIL_BUFFER_MAX_CHARS - 100);
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
    expect(strlen($buffer))->toBeLessThanOrEqual(Settings::PAIL_BUFFER_MAX_CHARS + 100);
    $this->assertStringContainsString('older lines trimmed', $buffer);
});
test('load pail records inline error on ssh failure', function () {
    [$user, $server, $site] = makeLaravelSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->andThrow(new \RuntimeException('ssh: connection refused'));

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
        ->set('laravel_tab', 'pail')
        ->call('loadLaravelPail', $executor)
        ->assertHasErrors('laravel_pail')
        ->assertSet('laravelPailLoaded', false);
});
