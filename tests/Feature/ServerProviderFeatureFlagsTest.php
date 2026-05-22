<?php


namespace Tests\Feature\ServerProviderFeatureFlagsTest;
use App\Actions\Servers\ListServerProviderCards;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Models\Organization;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('list server provider cards only includes enabled providers', function () {
    // Disable everything in the catalog, then explicitly enable the
    // two we want — otherwise env-driven defaults for aws_lambda /
    // digitalocean_functions / digitalocean_kubernetes leak in.
    $allProviders = array_keys(config('server_providers.enabled', []));
    foreach ($allProviders as $id) {
        config(['server_providers.enabled.'.$id => false]);
    }
    config(['server_providers.enabled.digitalocean' => true]);
    config(['server_providers.enabled.custom' => true]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $ids = array_column(ListServerProviderCards::run($org), 'id');
    sort($ids);

    expect($ids)->toBe(['custom', 'digitalocean']);
});

test('credentials nav omits disabled providers', function () {
    config(['server_providers.enabled.digitalocean' => true]);
    config(['server_providers.enabled.hetzner' => false]);
    config(['server_providers.enabled.linode' => false]);

    $nav = CredentialsIndex::credentialProviderNav();
    expect($nav)->not->toBeEmpty();
    $allIds = [];
    foreach ($nav as $group) {
        foreach ($group['items'] as $item) {
            $allIds[] = $item['id'];
        }
    }
    expect($allIds)->toContain('digitalocean');
    expect($allIds)->not->toContain('hetzner');
});