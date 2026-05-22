<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\CreateCustomTest;
use App\Enums\SiteType;
use App\Jobs\ProvisionCustomSiteJob;
use App\Livewire\Sites\Create as SiteCreate;
use App\Livewire\Sites\CreateCustom;
use App\Models\Organization;
use App\Models\Script;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('vm host shows custom entry point on create page', function () {
    $user = userWithOrganization();
    $server = vmServer($user);

    Livewire::actingAs($user)
        ->test(SiteCreate::class, ['server' => $server])
        ->assertSee('Create a Custom site');
});
test('docker host hides custom entry point', function () {
    $user = userWithOrganization();
    $server = dockerServer($user);

    Livewire::actingAs($user)
        ->test(SiteCreate::class, ['server' => $server])
        ->assertDontSee('Create a Custom site');
});
test('creating custom site with repo persists git mode', function () {
    Bus::fake();
    $user = userWithOrganization();
    $server = vmServer($user);

    Livewire::actingAs($user)
        ->test(CreateCustom::class, ['server' => $server])
        ->set('name', 'worker-queue')
        ->set('git_repository_url', 'git@github.com:me/worker.git')
        ->set('git_branch', 'main')
        ->call('store')
        ->assertHasNoErrors();

    $site = Site::query()->where('server_id', $server->id)->firstOrFail();

    expect($site->type)->toBe(SiteType::Custom);
    expect($site->name)->toBe('worker-queue');
    expect($site->git_repository_url)->toBe('git@github.com:me/worker.git');
    expect($site->git_branch)->toBe('main');
    expect($site->status)->toBe(Site::STATUS_PENDING);
    expect($site->deploy_script_id)->not->toBeNull();
    expect($site->deploy_strategy)->toBe('simple');

    $script = Script::query()->whereKey($site->deploy_script_id)->firstOrFail();
    expect($script->source)->toBe('site:custom_auto');

    Bus::assertDispatched(ProvisionCustomSiteJob::class);
});
test('creating custom site without repo persists no repo mode', function () {
    Bus::fake();
    $user = userWithOrganization();
    $server = vmServer($user);

    Livewire::actingAs($user)
        ->test(CreateCustom::class, ['server' => $server])
        ->set('name', 'ci-target')
        ->set('git_repository_url', '')
        ->call('store')
        ->assertHasNoErrors();

    $site = Site::query()->where('server_id', $server->id)->firstOrFail();
    expect($site->type)->toBe(SiteType::Custom);
    expect($site->git_repository_url)->toBeNull();
    expect($site->git_branch)->toBeNull();

    Bus::assertDispatched(ProvisionCustomSiteJob::class);
});
test('non vm host rejects custom create', function () {
    $user = userWithOrganization();
    $server = dockerServer($user);

    $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

    Livewire::actingAs($user)
        ->test(CreateCustom::class, ['server' => $server]);
});
function vmServer(User $user): Server
{
    return Server::factory()->ready()->create([
        'organization_id' => $user->currentOrganization()->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_VM, 'webserver' => 'nginx'],
    ]);
}
function dockerServer(User $user): Server
{
    return Server::factory()->ready()->create([
        'organization_id' => $user->currentOrganization()->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DOCKER],
    ]);
}
function userWithOrganization(): User
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);
    $user->setRelation('currentOrganization', $organization);
    session(['current_organization_id' => $organization->id]);

    return $user;
}
