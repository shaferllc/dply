<?php

namespace Tests\Feature;

use App\Actions\Servers\ListServerProviderCards;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerProviderFeatureFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_server_provider_cards_only_includes_enabled_providers(): void
    {
        config(['server_providers.enabled.digitalocean' => true]);
        config(['server_providers.enabled.hetzner' => false]);
        config(['server_providers.enabled.custom' => true]);

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $ids = array_column(ListServerProviderCards::run($org), 'id');
        sort($ids);

        $this->assertSame(['custom', 'digitalocean'], $ids);
    }

    public function test_credentials_nav_omits_disabled_providers(): void
    {
        config(['server_providers.enabled.digitalocean' => true]);
        config(['server_providers.enabled.hetzner' => false]);
        config(['server_providers.enabled.linode' => false]);

        $nav = CredentialsIndex::credentialProviderNav();
        $this->assertNotEmpty($nav);
        $allIds = [];
        foreach ($nav as $group) {
            foreach ($group['items'] as $item) {
                $allIds[] = $item['id'];
            }
        }
        $this->assertContains('digitalocean', $allIds);
        $this->assertNotContains('hetzner', $allIds);
    }
}
