<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Livewire\Sites\WordPress\WordPressSection;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function wpDirectoryFixture(string $phpVersion = '8.1'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $server = Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => 'vm',
            'php_version' => $phpVersion,
            'installed_stack' => ['php_version' => $phpVersion],
        ],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['scaffold' => ['framework' => 'wordpress', 'layout' => 'classic']],
    ]);

    return [$user, $site];
}

test('typing in the plugin search fills suggestions from wordpress.org', function (): void {
    Http::fake(['api.wordpress.org/*' => Http::response(['plugins' => [
        ['slug' => 'contact-form-7', 'name' => 'Contact Form 7', 'active_installs' => 5000000, 'rating' => 80],
    ]])]);
    [$user, $site] = wpDirectoryFixture();

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('pluginSearch', 'forms');

    expect($component->get('pluginSuggestions'))->toHaveCount(1)
        ->and($component->get('pluginSuggestions')[0]['slug'])->toBe('contact-form-7')
        ->and($component->get('pluginSuggestions')[0]['installed'])->toBeFalse();
});

/**
 * wp.org accepts any install, and WordPress then refuses to activate a plugin
 * whose declared PHP minimum the site misses. The detail card must stop that
 * before anything touches the server.
 */
test('a plugin requiring a newer php than the site cannot be installed', function (): void {
    Http::fake(['api.wordpress.org/*' => Http::response([
        'slug' => 'needs-new-php',
        'name' => 'Needs New PHP',
        'version' => '2.0.0',
        'requires_php' => '8.3',
        'versions' => ['2.0.0' => 'x', '1.9.0' => 'x', 'trunk' => 'x'],
    ])]);
    [$user, $site] = wpDirectoryFixture('8.1');

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        // Core version already known, so no wp-cli call is needed for it.
        ->set('core', ['version' => '6.6.1', 'update_available' => false, 'latest' => null])
        ->call('showPluginDetail', 'needs-new-php');

    $detail = $component->get('pluginDetail');
    expect($detail['compatibility']['blockers'])->toHaveCount(1)
        // trunk is the unreleased development head, never offered.
        ->and($detail['versions'])->toBe(['2.0.0', '1.9.0']);

    $component->call('installFromDirectory')->assertHasErrors('plugins');
});

test('bulk actions ignore slugs that are not installed', function (): void {
    [$user, $site] = wpDirectoryFixture();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('plugins', [['name' => 'akismet', 'status' => 'inactive', 'version' => '5.0', 'update' => 'none', 'auto_update' => 'off', 'advisories' => []]])
        ->set('selectedPlugins', ['not-installed'])
        ->call('bulkPluginAction', 'activate')
        // Nothing valid to act on, so nothing ran and the selection stands.
        ->assertSet('selectedPlugins', ['not-installed']);
});
