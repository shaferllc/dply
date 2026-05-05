<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteSettingsSidebar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SiteSettingsCliPanelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_settings_section_renders_a_cli_panel(): void
    {
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

        $sections = collect(SiteSettingsSidebar::items($site->fresh(), $server))
            ->pluck('id')
            ->reject(fn (string $id): bool => in_array($id, ['webserver-config', 'monitor', 'commits'], true))
            ->values()
            ->all();

        $this->assertNotEmpty($sections, 'Sidebar should expose at least one section.');

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
    }
}
