<?php

declare(strict_types=1);

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Livewire\Sites\Settings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('add basic auth user can restore username marked pending removal', function (): void {
    Bus::fake();
    [$user, $server, $site] = makeBasicAuthUserSite();

    SiteBasicAuthUser::factory()->create([
        'site_id' => $site->id,
        'username' => 'shafer',
        'path' => '/',
        'pending_removal_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'basic-auth'])
        ->set('new_basic_auth_username', 'shafer')
        ->set('new_basic_auth_password', 'newpassword1')
        ->set('new_basic_auth_path', '/')
        ->call('addBasicAuthUser')
        ->assertHasNoErrors();

    $row = SiteBasicAuthUser::query()
        ->where('site_id', $site->id)
        ->where('username', 'shafer')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->pending_removal_at)->toBeNull()
        ->and($row->password_hash)->not->toBeEmpty();

    Bus::assertDispatched(ApplySiteWebserverConfigJob::class);
});

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeBasicAuthUserSite(): array
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
