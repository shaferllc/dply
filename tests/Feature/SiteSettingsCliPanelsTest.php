<?php

declare(strict_types=1);

namespace Tests\Feature\SiteSettingsCliPanelsTest;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteSettingsSidebar;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('every settings section renders a cli panel', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    session(['current_organization_id' => $organization->id]);
    $this->actingAs($user);

    // External-route sidebar items (cron / schedule / daemons / queue-workers / backups /
    // commits / monitor / webserver-config) navigate away from the settings page rather
    // than rendering a section inside it, so SiteSettings has no panel for them. The CLI
    // snippet requirement is for *internal* sections only — items whose sidebar entry
    // lacks an explicit `route` field.
    $sections = collect(SiteSettingsSidebar::items($site->fresh(), $server))
        ->reject(fn (array $item): bool => isset($item['route']))
        ->pluck('id')
        ->reject(fn (string $id): bool => in_array($id, ['webserver-config', 'monitor', 'commits'], true))
        ->values()
        ->all();

    expect($sections)->not->toBeEmpty('Sidebar should expose at least one section.');

    foreach ($sections as $section) {
        $component = Livewire::test(SiteSettings::class, [
            'server' => $server,
            'site' => $site,
            'section' => $section,
        ]);

        $this->assertStringContainsString(
            'data-cli-snippet',
            $component->html(),
            "Section [$section] should render a CLI snippet panel.",
        );
    }
});
