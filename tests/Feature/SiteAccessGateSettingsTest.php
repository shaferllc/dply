<?php

declare(strict_types=1);

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Livewire\Sites\Settings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteAccessGate;
use App\Models\SiteAccessGatePassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('add form gate password dispatches webserver apply job and persists gate row', function (): void {
    Bus::fake();
    [$user, $server, $site] = makeAccessGateSettingsSite();

    Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'basic-auth'])
        ->set('new_form_gate_label', 'Sarah')
        ->set('form_gate_password', 'gatepassword1')
        ->call('addFormGatePassword')
        ->assertHasNoErrors();

    $gate = SiteAccessGate::query()->where('site_id', $site->id)->first();
    $password = SiteAccessGatePassword::query()->where('site_id', $site->id)->first();

    expect($gate)->not->toBeNull()
        ->and($gate->method)->toBe(SiteAccessGate::METHOD_FORM_PASSWORD)
        ->and($password)->not->toBeNull()
        ->and($password->label)->toBe('Sarah')
        ->and($password->password_verifier)->toBe(hash('sha256', $password->password_salt.'gatepassword1'));

    Bus::assertDispatched(ApplySiteWebserverConfigJob::class);
});

test('authentication page shows password gate method selector', function (): void {
    [$user, $server, $site] = makeAccessGateSettingsSite();

    Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'basic-auth'])
        ->set('access_gate_method', SiteAccessGate::METHOD_FORM_PASSWORD)
        ->assertSee('Password gate')
        ->assertSee('HTTP basic auth')
        ->assertSee('Login log');
});

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeAccessGateSettingsSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    return [$user, $server, $site];
}
