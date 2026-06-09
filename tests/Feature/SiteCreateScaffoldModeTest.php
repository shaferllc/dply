<?php

declare(strict_types=1);

namespace Tests\Feature\SiteCreateScaffoldModeTest;

use App\Jobs\RunLaravelScaffoldJob;
use App\Jobs\RunWordPressScaffoldJob;
use App\Livewire\Sites\Create as SitesCreate;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function userWithOrgAndServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return [$user, $server];
}
test('default mode is import when flag off', function () {
    config(['dply.scaffold_v1_enabled' => false]);
    [$user, $server] = userWithOrgAndServer();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->assertSet('form.mode', 'import')
        // Mode toggle hidden when the flag is off — clean upgrade path
        // for installs that haven't enabled scaffolding yet.
        ->assertDontSee('Scaffold a new app');
});
test('mode toggle renders when flag on', function () {
    config(['dply.scaffold_v1_enabled' => true]);
    [$user, $server] = userWithOrgAndServer();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->assertSee('Scaffold a new app')
        ->assertSee('Import an existing repo');
});
test('choosing scaffold mode swaps panels', function () {
    config(['dply.scaffold_v1_enabled' => true]);
    [$user, $server] = userWithOrgAndServer();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->call('chooseScaffoldMode')
        ->assertSet('form.mode', 'scaffold')
        ->assertSee('Pick a starter')
        ->assertSee('Laravel app')
        ->assertSee('WordPress');
});
test('choosing scaffold framework persists', function () {
    config(['dply.scaffold_v1_enabled' => true]);
    [$user, $server] = userWithOrgAndServer();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->call('chooseScaffoldMode')
        ->call('chooseScaffoldFramework', 'laravel')
        ->assertSet('form.scaffold_framework', 'laravel')
        ->call('chooseScaffoldFramework', 'wordpress')
        ->assertSet('form.scaffold_framework', 'wordpress');
});
test('invalid framework is ignored', function () {
    config(['dply.scaffold_v1_enabled' => true]);
    [$user, $server] = userWithOrgAndServer();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->call('chooseScaffoldFramework', 'symfony')
        ->assertSet('form.scaffold_framework', '');
});
test('chooseimport clears scaffold state', function () {
    config(['dply.scaffold_v1_enabled' => true]);
    [$user, $server] = userWithOrgAndServer();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->call('chooseScaffoldMode')
        ->call('chooseScaffoldFramework', 'wordpress')
        ->set('form.scaffold_admin_email', 'me@example.com')
        ->call('chooseImportMode')
        ->assertSet('form.mode', 'import')
        ->assertSet('form.scaffold_framework', '')
        ->assertSet('form.scaffold_admin_email', '');
});
test('storescaffold creates site in scaffolding status', function () {
    Bus::fake();
    // Prevent the WP pipeline job from running inline
    config(['dply.scaffold_v1_enabled' => true]);
    [$user, $server] = userWithOrgAndServer();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->call('chooseScaffoldMode')
        ->call('chooseScaffoldFramework', 'wordpress')
        ->set('form.name', 'My WP Blog')
        ->set('form.scaffold_admin_email', 'admin@example.com')
        ->call('storeScaffold');

    $site = Site::query()->sole();
    expect($site->name)->toBe('My WP Blog');
    expect($site->slug)->toBe('my-wp-blog');
    expect($site->status)->toBe(Site::STATUS_SCAFFOLDING);
    expect($site->meta['scaffold']['framework'])->toBe('wordpress');
    expect($site->meta['scaffold']['admin_email'])->toBe('admin@example.com');
    expect($site->meta['scaffold']['requested_by_user_id'])->toBe($user->id);
    expect($site->meta['scaffold']['requested_hostname'])->toBeNull();

    Bus::assertDispatched(RunWordPressScaffoldJob::class,
        fn ($job) => $job->siteId === $site->id);
});
test('storescaffold records optional hostname', function () {
    Bus::fake();
    config(['dply.scaffold_v1_enabled' => true]);
    [$user, $server] = userWithOrgAndServer();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->call('chooseScaffoldMode')
        ->call('chooseScaffoldFramework', 'laravel')
        ->set('form.name', 'My Laravel App')
        ->set('form.scaffold_admin_email', 'me@example.com')
        ->set('form.primary_hostname', 'app.example.com')
        ->call('storeScaffold');

    $site = Site::query()->sole();
    expect($site->meta['scaffold']['requested_hostname'])->toBe('app.example.com');

    Bus::assertDispatched(RunLaravelScaffoldJob::class,
        fn ($job) => $job->siteId === $site->id);
});
test('storescaffold validates required fields', function () {
    config(['dply.scaffold_v1_enabled' => true]);
    [$user, $server] = userWithOrgAndServer();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->call('chooseScaffoldMode')
        ->call('storeScaffold')
        ->assertHasErrors(['form.name', 'form.scaffold_framework', 'form.scaffold_admin_email']);

    expect(Site::query()->count())->toBe(0);
});
test('storescaffold blocks when feature flag off', function () {
    config(['dply.scaffold_v1_enabled' => false]);
    [$user, $server] = userWithOrgAndServer();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.mode', 'scaffold')
        ->set('form.scaffold_framework', 'laravel')
        ->set('form.name', 'wat')
        ->set('form.scaffold_admin_email', 'me@example.com')
        ->call('storeScaffold')
        ->assertHasErrors('form.mode');

    expect(Site::query()->count())->toBe(0);
});
