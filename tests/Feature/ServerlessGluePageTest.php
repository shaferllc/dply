<?php

declare(strict_types=1);

namespace Tests\Feature\ServerlessGluePageTest;

use App\Modules\Serverless\Livewire\Glue;
use App\Models\FunctionAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('surface.serverless');

function glueUserWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

function glueFunctionsSite(Organization $org, User $user): Site
{
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'digitalocean_functions' => [
                'api_host' => 'https://faas-nyc1.example.com',
                'access_key' => 'keyid:keysecret',
            ],
        ],
    ]);

    return Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'Glue package',
        'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
    ]);
}

test('serverless glue page is hidden without feature flag', function (): void {
    Feature::define('surface.serverless', fn (): bool => false);
    Feature::flushCache();

    $user = glueUserWithOrg();

    $this->actingAs($user)
        ->get(route('serverless.glue'))
        ->assertStatus(400);
});

test('serverless glue page renders recipe catalog', function (): void {
    $user = glueUserWithOrg();

    $this->actingAs($user)
        ->get(route('serverless.glue'))
        ->assertOk()
        ->assertSee(__('Serverless glue'))
        ->assertSee(__('Edge deploy hook → serverless sequence'));
});

test('livewire can save and deploy a sequence', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $user = glueUserWithOrg();
    $org = Organization::query()->first();
    $site = glueFunctionsSite($org, $user);

    $first = FunctionAction::query()->create([
        'site_id' => $site->id,
        'name' => 'fetch',
        'kind' => FunctionAction::KIND_CODE,
        'runtime' => 'nodejs:18',
    ]);

    $second = FunctionAction::query()->create([
        'site_id' => $site->id,
        'name' => 'transform',
        'kind' => FunctionAction::KIND_CODE,
        'runtime' => 'nodejs:18',
    ]);

    Livewire::actingAs($user)
        ->test(Glue::class)
        ->set('tab', 'sequences')
        ->set('sequenceServerId', (string) $site->server_id)
        ->set('sequenceSiteId', (string) $site->id)
        ->set('sequenceName', 'pipeline')
        ->set('sequenceComponentIds', [(string) $first->id, (string) $second->id])
        ->call('saveSequence')
        ->assertHasNoErrors();

    $sequence = FunctionAction::query()->where('site_id', $site->id)->where('name', 'pipeline')->first();

    expect($sequence)->not->toBeNull();
    expect($sequence->isSequence())->toBeTrue();

    Livewire::actingAs($user)
        ->test(Glue::class)
        ->call('deploySequence', (string) $sequence->id);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/actions/pipeline'));
});
